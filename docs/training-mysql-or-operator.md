# Modul Training: Pencarian MySQL dengan Operator OR

## Daftar Isi

1. [Latar Belakang](#latar-belakang)
2. [Mengapa OR Lambat di MySQL?](#mengapa-or-lambat-di-mysql)
3. [Solusi dan Alternatif](#solusi-dan-alternatif)
4. [Perbandingan Performa](#perbandingan-performa)
5. [Kesimpulan](#kesimpulan)

---

## Latar Belakang

Operator `OR` adalah salah satu operator logika yang paling sering digunakan dalam query SQL, khususnya pada fitur pencarian (*search*). Pola yang umum dipakai — termasuk yang sudah menggunakan *start-with* agar lebih cepat — kira-kira seperti ini:

```sql
SELECT * FROM products
WHERE name LIKE 'samsung%'
OR brand_name LIKE 'samsung%';
```

Secara logika, query di atas terlihat wajar dan bahkan sudah "lebih baik" karena tidak ada wildcard di awal string. Namun, seiring bertambahnya jumlah data (*data volume*), query semacam ini **tetap** menunjukkan masalah performa yang serius.

### Gambaran Masalah

Bayangkan sebuah tabel `products` yang berisi **10 juta baris** data. Ketika pengguna mengetik kata kunci di kotak pencarian, aplikasi menjalankan query dengan `OR` seperti contoh di atas. Hasilnya:

- Response API menjadi lambat (bisa lebih dari 5–10 detik)
- Beban CPU database melonjak drastis
- Pengguna lain yang mengakses sistem ikut merasakan penurunan performa
- Dalam kondisi traffic tinggi, server database bisa tidak responsif

---

## Mengapa OR Lambat di MySQL?

### 1. OR Menghambat Penggunaan Index, Bahkan dengan Start-With

MySQL menggunakan struktur data **B-Tree Index** untuk mempercepat pencarian. Index ini bekerja sangat efisien untuk pola *start-with* (`LIKE 'kata%'`) pada **satu kolom** — MySQL bisa langsung melompat ke posisi yang tepat di index, seperti membuka buku dari daftar isi.

Masalahnya dimulai ketika `OR` masuk ke gambar:

```sql
-- Index pada `name` ada dan BISA dipakai untuk LIKE 'samsung%' sendiri.
-- Tapi begitu ada OR dengan kolom lain, optimizer sering tidak bisa pakai keduanya secara efisien.
SELECT * FROM products
WHERE name LIKE 'samsung%'        -- kondisi 1: bisa pakai index
OR brand_name LIKE 'samsung%';    -- kondisi 2: kolom lain, index berbeda
```

Ketika ada `OR` antara dua kolom yang masing-masing memiliki index berbeda, MySQL harus:
1. Scan index pertama untuk kondisi pertama → hasilkan *result set A*
2. Scan index kedua untuk kondisi kedua → hasilkan *result set B*
3. Gabungkan A dan B, buang duplikat

Proses penggabungan ini (disebut **Index Merge Union**) memiliki overhead yang signifikan. Dan dalam banyak kasus — terutama jika selectivity-nya rendah atau tabel hasil JOIN besar — MySQL justru memilih untuk **meninggalkan index sama sekali** dan melakukan full scan, karena optimizer menghitung bahwa full scan lebih murah daripada bolak-balik merge.

> **Analogi:** Bayangkan kamu diminta mencari nama di dua buku telepon yang berbeda, lalu menggabungkan hasilnya sambil membuang yang ganda. Kalau ternyata hampir semua halaman perlu diperiksa di kedua buku, akan lebih cepat langsung membaca satu buku dari awal hingga akhir — daripada bolak-balik berpindah buku dan terus mencocokkan daftar. Itulah keputusan yang diambil MySQL: kalau "ongkos" menggabungkan dua hasil pencarian sudah terlalu besar, lebih baik scan semuanya sekaligus.

### 2. OR Lintas Tabel JOIN Memaksa Full Scan

Masalah semakin parah ketika `OR` menyeberang ke kolom dari tabel yang di-JOIN. MySQL tidak dapat mendorong (*push down*) filter `OR` ke masing-masing tabel secara terpisah sebelum JOIN dilakukan.

Anda bisa memverifikasi ini menggunakan perintah `EXPLAIN`:

```sql
EXPLAIN
SELECT p.id, p.name, b.name AS brand_name
FROM products p
LEFT JOIN brands b ON b.id = p.id_brand
WHERE p.name LIKE 'samsung%'
OR b.name LIKE 'samsung%';
```

Output yang mengkhawatirkan:

```
+----+-------------+-------+------+---------------+------+---------+------+---------+-------------+
| id | select_type | table | type | possible_keys | key  | key_len | ref  | rows    | Extra       |
+----+-------------+-------+------+---------------+------+---------+------+---------+-------------+
|  1 | SIMPLE      | p     | ALL  | NULL          | NULL | NULL    | NULL | 9876543 | Using where |
|  1 | SIMPLE      | b     | ALL  | NULL          | NULL | NULL    | NULL |     250 | Using where |
+----+-------------+-------+------+---------------+------+---------+------+---------+-------------+
```

- `type: ALL` di kedua tabel → full table scan di keduanya
- `key: NULL` → index sama sekali tidak digunakan, meski sudah ada
- MySQL harus memproses seluruh hasil JOIN (bisa miliaran kombinasi) sebelum mengevaluasi kondisi `OR`

Bandingkan dengan query **tanpa OR** (hanya satu kondisi):

```sql
EXPLAIN SELECT * FROM products WHERE name LIKE 'samsung%';
```

```
+----+-------------+----------+-------+----------------+----------------+---------+------+------+-----------------------+
| id | select_type | table    | type  | possible_keys  | key            | key_len | ref  | rows | Extra                 |
+----+-------------+----------+-------+----------------+----------------+---------+------+------+-----------------------+
|  1 | SIMPLE      | products | range | idx_prod_name  | idx_prod_name  | 767     | NULL |  312 | Using index condition |
+----+-------------+----------+-------+----------------+----------------+---------+------+------+-----------------------+
```

- `type: range` → hanya scan sebagian index (sangat efisien)
- `rows: 312` → MySQL hanya memeriksa 312 baris, bukan jutaan

### 3. OR dengan Banyak Kondisi Semakin Parah

Semakin banyak kondisi `OR` yang ditambahkan, semakin berat beban yang harus ditanggung MySQL:

```sql
-- Makin banyak OR, makin lambat — bahkan dengan pola start-with sekalipun
SELECT * FROM products
WHERE name LIKE 'samsung%'
OR brand_name LIKE 'samsung%'
OR description LIKE 'samsung%'
OR category LIKE 'samsung%'
OR tags LIKE 'samsung%';
```

Setiap tambahan kondisi `OR` meningkatkan kompleksitas query secara signifikan. MySQL harus mengevaluasi setiap kondisi untuk setiap baris, dan menggabungkan semua result set.

### 4. OR Bisa Merusak Urutan Eksekusi Query

Dalam query yang melibatkan `JOIN`, penggunaan `OR` dapat secara tidak sengaja mengubah logika filter. Contoh:

```sql
SELECT p.*, b.name AS brand_name
FROM products p
LEFT JOIN brands b ON b.id = p.id_brand
WHERE p.name LIKE 'samsung%'
OR b.name LIKE 'samsung%'; -- kondisi OR lintas tabel mengubah perilaku LEFT JOIN
```

Query ini berpotensi mengembalikan data yang tidak diharapkan. Karena `OR` melibatkan kolom dari tabel yang di-LEFT JOIN, produk yang tidak memiliki brand (brand = NULL) bisa tetap muncul jika `p.name LIKE 'samsung%'` terpenuhi — dengan `brand_name` bernilai NULL. Sebaliknya, produk yang brandnya cocok tapi namanya tidak cocok juga akan muncul. Perilaku ini sering tidak sesuai ekspektasi dan sulit di-debug.

---

## Solusi dan Alternatif

### Solusi 1: Gunakan UNION ALL (Pengganti OR yang Efisien)

**UNION ALL** memecah satu query besar dengan `OR` menjadi dua query terpisah yang masing-masing bisa memanfaatkan index-nya sendiri, lalu hasilnya digabungkan.

```sql
-- Sebelum (dengan OR - lambat, bahkan dengan start-with):
SELECT p.id, p.name, b.name AS brand_name
FROM products p
LEFT JOIN brands b ON b.id = p.id_brand
WHERE p.name LIKE 'samsung%'
OR b.name LIKE 'samsung%';

-- Sesudah (dengan UNION ALL - lebih cepat):
SELECT p.id, p.name, b.name AS brand_name
FROM products p
LEFT JOIN brands b ON b.id = p.id_brand
WHERE p.name LIKE 'samsung%'

UNION ALL

SELECT p.id, p.name, b.name AS brand_name
FROM products p
LEFT JOIN brands b ON b.id = p.id_brand
WHERE b.name LIKE 'samsung%'
AND p.name NOT LIKE 'samsung%'; -- hindari duplikat
```

**Keuntungan UNION ALL:**
- Setiap sub-query bisa menggunakan index-nya masing-masing
- MySQL Query Optimizer dapat mengoptimalkan setiap bagian secara independen
- Performa secara keseluruhan lebih baik, terutama untuk tabel besar

**Kapan menggunakan `UNION` vs `UNION ALL`:**
- `UNION ALL`: Lebih cepat, tidak menghapus duplikat (gunakan ini jika data sudah dijamin unik atau duplikat tidak masalah)
- `UNION`: Menghapus duplikat otomatis, tetapi lebih lambat karena memerlukan proses deduplikasi

---

### Solusi 2: Gunakan FULLTEXT Search (Rekomendasi untuk Pencarian Teks)

MySQL memiliki fitur **FULLTEXT Index** yang dirancang khusus untuk pencarian teks. Ini jauh lebih cepat dibandingkan `OR` + `LIKE 'kata%'` lintas kolom, terutama untuk kebutuhan pencarian kata kunci dari banyak kolom.

**Langkah 1: Buat FULLTEXT Index**

```sql
-- Tambahkan FULLTEXT index pada kolom yang ingin dicari
ALTER TABLE products ADD FULLTEXT INDEX ft_product_search (name, description);
ALTER TABLE brands ADD FULLTEXT INDEX ft_brand_search (name);
```

**Langkah 2: Gunakan MATCH...AGAINST**

```sql
-- Pencarian menggunakan FULLTEXT (jauh lebih cepat dari OR + LIKE 'kata%')
SELECT p.id, p.name, b.name AS brand_name
FROM products p
LEFT JOIN brands b ON b.id = p.id_brand
WHERE MATCH(p.name, p.description) AGAINST ('samsung' IN BOOLEAN MODE);
```

**Mode pencarian FULLTEXT:**

```sql
-- Natural Language Mode (default): mencari kata yang relevan secara natural
SELECT * FROM products
WHERE MATCH(name) AGAINST ('samsung galaxy');

-- Boolean Mode: mendukung operator khusus
SELECT * FROM products
WHERE MATCH(name) AGAINST ('+samsung -apple' IN BOOLEAN MODE);
-- (+) kata harus ada, (-) kata tidak boleh ada

-- Query Expansion: memperluas pencarian berdasarkan hasil awal
SELECT * FROM products
WHERE MATCH(name) AGAINST ('samsung' WITH QUERY EXPANSION);
```

**Perbandingan EXPLAIN:**

```sql
EXPLAIN SELECT * FROM products
WHERE MATCH(name) AGAINST ('samsung' IN BOOLEAN MODE);
```

Output yang baik:

```
+----+-------------+----------+----------+--------------------+--------------------+---------+------+------+-------------+
| id | select_type | table    | type     | possible_keys      | key                | key_len | ref  | rows | Extra       |
+----+-------------+----------+----------+--------------------+--------------------+---------+------+------+-------------+
|  1 | SIMPLE      | products | fulltext | ft_product_search  | ft_product_search  | 0       | NULL | 1    | Using where |
+----+-------------+----------+----------+--------------------+--------------------+---------+------+------+-------------+
```

- `type: fulltext` → menggunakan fulltext index (sangat baik)
- `rows: 1` → MySQL sangat efisien dalam memperkirakan baris yang akan diambil

---

### Solusi 3: Gunakan Kolom Gabungan + Index (Search Column)

Strategi ini membuat satu kolom khusus yang berisi gabungan semua teks yang ingin dicari, lalu memasang index di kolom tersebut.

**Langkah 1: Tambah kolom `search_text`**

```sql
ALTER TABLE products ADD COLUMN search_text TEXT;

-- Isi dengan gabungan kolom-kolom yang relevan
UPDATE products p
INNER JOIN brands b ON b.id = p.id_brand
SET p.search_text = CONCAT_WS(' ', p.name, b.name, p.description);

-- Untuk produk yang tidak memiliki brand (jika relasi bersifat opsional)
UPDATE products p
SET p.search_text = CONCAT_WS(' ', p.name, p.description)
WHERE p.id_brand IS NULL;
```

**Langkah 2: Buat FULLTEXT Index di kolom tersebut**

```sql
ALTER TABLE products ADD FULLTEXT INDEX ft_search (search_text);
```

**Langkah 3: Query menjadi sangat sederhana**

```sql
SELECT * FROM products
WHERE MATCH(search_text) AGAINST ('samsung' IN BOOLEAN MODE);
```

**Keuntungan:**
- Satu index, satu kolom — query sangat bersih dan cepat
- Tidak perlu JOIN hanya untuk keperluan pencarian
- Mudah di-maintain dan di-update

**Catatan:** Kolom `search_text` perlu di-update setiap kali data berubah. Bisa dilakukan via trigger atau proses background.

---

### Solusi 4: Optimalkan Index untuk OR yang Tidak Bisa Dihindari

Jika penggunaan `OR` tidak bisa dihindari, pastikan setiap kolom dalam kondisi `OR` memiliki index-nya masing-masing. MySQL memiliki fitur **Index Merge** yang bisa menggabungkan hasil dari beberapa index.

```sql
-- Pastikan index ada di setiap kolom
CREATE INDEX idx_product_name ON products(name);
CREATE INDEX idx_brand_name ON brands(name);
```

Lalu cek apakah MySQL menggunakan Index Merge:

```sql
EXPLAIN SELECT * FROM products
WHERE name = 'Samsung Galaxy A54'
OR name = 'iPhone 15';
```

Jika output menunjukkan `type: index_merge` dan `Extra: Using union(idx_product_name,idx_product_name)`, berarti MySQL sudah menggunakan beberapa index sekaligus.

**Catatan penting:** Index Merge bisa bekerja untuk range scan seperti `LIKE 'kata%'` dalam **satu tabel**. Namun ketika `OR` menyeberang ke tabel lain melalui JOIN, Index Merge tidak lagi berlaku dan MySQL akan fallback ke full scan.

---

### Solusi 5: Caching Hasil Pencarian

Untuk pencarian yang sering diulang dengan keyword yang sama, implementasikan **caching** di layer aplikasi:

```php
// Contoh implementasi cache di Lumen/Laravel
public function search(Request $request) {
    $keyword = $request->input('keyword');
    $cacheKey = 'search_' . md5($keyword);

    $result = Cache::remember($cacheKey, 300, function () use ($keyword) {
        return DB::table('products')
            ->select('id', 'name', 'search_text')
            ->whereRaw('MATCH(search_text) AGAINST (? IN BOOLEAN MODE)', [$keyword])
            ->paginate(10);
    });

    return response()->json(['success' => true, 'data' => $result]);
}
```

Dengan TTL (Time To Live) cache 5 menit, query berat hanya dijalankan sekali setiap 5 menit untuk keyword yang sama.

---

## Perbandingan Performa

Berikut perbandingan estimasi performa untuk tabel dengan **1 juta baris**:

| Metode Query | Waktu Eksekusi | Index Digunakan | Catatan |
|---|---|---|---|
| `OR` + `LIKE 'kata%'` (lintas tabel JOIN) | ~3000 ms | Tidak | Full scan karena OR lintas JOIN |
| `OR` + `LIKE 'kata%'` (satu tabel, Index Merge) | ~800 ms | Sebagian | Index Merge, tapi ada overhead merge |
| `UNION ALL` + `LIKE 'kata%'` | ~100 ms | Ya (range) | Setiap sub-query pakai index sendiri |
| `MATCH...AGAINST` (FULLTEXT) | ~10 ms | Ya (FULLTEXT) | Optimal untuk pencarian teks bebas |
| Hasil dari cache | < 5 ms | - | Tidak menyentuh database |

---

## Kesimpulan

Penggunaan operator `OR` dalam query pencarian MySQL adalah pola yang mudah ditulis tetapi bermasalah di skala besar. **Ini berlaku bahkan ketika sudah menggunakan pola *start-with* (`LIKE 'kata%'`)** — karena akar masalahnya bukan hanya pada pola `LIKE`, melainkan pada kemampuan MySQL menggunakan index saat kondisi `OR` tersebar di beberapa kolom atau lintas tabel JOIN. Berikut rangkuman rekomendasi:

1. **Hindari `OR` lintas kolom/tabel untuk pencarian** — meski masing-masing kolom punya index dan sudah pakai `LIKE 'kata%'`, OR tetap memaksa full scan atau overhead Index Merge yang berat.

2. **Gunakan FULLTEXT Index** — ini adalah solusi terbaik untuk kebutuhan pencarian teks dari banyak kolom sekaligus di MySQL.

3. **Gunakan UNION ALL** sebagai alternatif `OR` ketika FULLTEXT tidak memungkinkan — setiap sub-query dapat dioptimalkan secara independen dengan index range scan.

4. **Selalu gunakan EXPLAIN** untuk menganalisis query sebelum deploy ke production — pastikan `type` bukan `ALL` dan `key` tidak `NULL`.

5. **Tambahkan caching** di layer aplikasi untuk mengurangi beban database pada pencarian yang sering berulang.

6. **Mulai migrasi secara bertahap** — tidak perlu mengubah semua query sekaligus. Prioritaskan query yang paling sering dijalankan dan paling lambat.

> **Prinsip utama:** Buat MySQL bekerja sesedikit mungkin dengan memastikan setiap query memanfaatkan index yang tepat — `OR` lintas kolom sering kali mencegah hal itu terjadi.
