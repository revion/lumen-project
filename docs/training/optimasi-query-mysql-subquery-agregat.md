# Modul Training: Optimasi Query MySQL — Subquery dengan Fungsi Agregat

**Topik:** Optimasi Query MySQL — Subquery Berkorelasi vs JOIN + GROUP BY  
**Level:** Intermediate  
**Estimasi Waktu:** 60–90 menit

---

## Daftar Isi

1. [Pendahuluan](#1-pendahuluan)
2. [Studi Kasus: Query yang Bermasalah](#2-studi-kasus-query-yang-bermasalah)
3. [Mengapa Subquery Berkorelasi Tidak Disarankan](#3-mengapa-subquery-berkorelasi-tidak-disarankan)
4. [Solusi: Rewrite dengan LEFT JOIN + GROUP BY](#4-solusi-rewrite-dengan-left-join--group-by)
5. [Perbandingan Rencana Eksekusi (EXPLAIN)](#5-perbandingan-rencana-eksekusi-explain)
6. [Rekomendasi Indeks](#6-rekomendasi-indeks)
7. [Contoh Kasus Tambahan](#7-contoh-kasus-tambahan)
8. [Latihan](#8-latihan)
9. [Ringkasan](#9-ringkasan)

---

## 1. Pendahuluan

Saat membangun fitur pelaporan atau dashboard, kita sering membutuhkan data agregat seperti:

- Berapa kali suatu produk dibeli? (`COUNT`)
- Berapa total nilai transaksi suatu produk? (`SUM`)
- Berapa rata-rata nilai pesanan per pelanggan? (`AVG`)

Salah satu cara yang sering ditulis oleh developer adalah meletakkan fungsi agregat tersebut di dalam **subquery berkorelasi** pada klausa `SELECT`. Cara ini terlihat intuitif, tetapi menyebabkan masalah performa yang serius pada dataset yang besar.

Modul ini membahas:
1. Mengapa subquery berkorelasi dengan agregat **tidak disarankan**
2. Solusi penulisan query yang mencapai tujuan yang sama namun jauh lebih efisien

### Skema Tabel yang Digunakan

```sql
-- Tabel produk
CREATE TABLE product (
    id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name  VARCHAR(150) NOT NULL,
    price DECIMAL(15, 2) NOT NULL
);

-- Tabel peta pembelian produk
-- Setiap baris = satu transaksi pembelian untuk sebuah produk
CREATE TABLE inv_product_map (
    id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pid   INT UNSIGNED NOT NULL,   -- FK ke product.id
    price DECIMAL(15, 2) NOT NULL, -- harga pada saat transaksi

    FOREIGN KEY (pid) REFERENCES product(id),
    INDEX idx_pid (pid)            -- indeks pada kolom JOIN
);
```

---

## 2. Studi Kasus: Query yang Bermasalah

### Query

```sql
SELECT
    p.name,
    p.price,
    (SELECT COUNT(*)
       FROM inv_product_map AS ipm
      WHERE ipm.pid = p.id) AS total_bought,
    (SELECT SUM(ipm.price)
       FROM inv_product_map AS ipm
      WHERE ipm.pid = p.id) AS total_amount
FROM product AS p;
```

### Penjelasan Tujuan

Query di atas bertujuan untuk menampilkan setiap produk beserta:
- `total_bought` — jumlah berapa kali produk tersebut dibeli
- `total_amount` — total nilai uang dari semua pembelian produk tersebut

Secara hasil, query ini **benar**. Namun secara performa, query ini sangat **tidak efisien**.

---

## 3. Mengapa Subquery Berkorelasi Tidak Disarankan

### 3.1 Apa Itu Subquery Berkorelasi?

**Subquery berkorelasi** (*correlated subquery*) adalah subquery yang merujuk kolom dari query luarnya (`outer query`). Pada contoh di atas, kedua subquery menggunakan `p.id` — nilai yang berasal dari setiap baris pada tabel `product` di query luar.

```sql
-- Ini adalah subquery berkorelasi karena merujuk p.id dari outer query
(SELECT COUNT(*) FROM inv_product_map AS ipm WHERE ipm.pid = p.id)
--                                                             ^^^^
--                                          kolom dari outer query
```

### 3.2 Cara MySQL Mengeksekusinya

MySQL mengeksekusi subquery berkorelasi dengan cara berikut:

```
Untuk setiap baris di tabel product (outer query):
    1. Ambil nilai p.id dari baris tersebut
    2. Jalankan subquery COUNT(*) dengan filter ipm.pid = p.id  → eksekusi ke-1
    3. Jalankan subquery SUM(ipm.price) dengan filter ipm.pid = p.id  → eksekusi ke-2
    4. Gabungkan hasilnya ke baris tersebut
    5. Pindah ke baris product berikutnya
```

Artinya:

| Jumlah baris di `product` | Subquery `COUNT` | Subquery `SUM` | Total eksekusi SQL |
|:---:|:---:|:---:|:---:|
| 100 | 100× | 100× | **201** |
| 1.000 | 1.000× | 1.000× | **2.001** |
| 10.000 | 10.000× | 10.000× | **20.001** |
| 100.000 | 100.000× | 100.000× | **200.001** |

> **Rumus:** Jika tabel `product` memiliki **N** baris dan ada **K** subquery berkorelasi,  
> total eksekusi SQL = **N × K + 1**.

Dengan 2 subquery dan 10.000 produk, MySQL melakukan **20.001 operasi** database.

### 3.3 Dampak Performa

#### I/O Disk Berulang
Setiap eksekusi subquery memerlukan akses ke tabel `inv_product_map`. Meski ada indeks, overhead dari ribuan pemanggilan terpisah tetap signifikan karena:
- Setiap pemanggilan membuka koneksi ke storage engine
- Query optimizer perlu memeriksa ulang rencana eksekusi untuk setiap subquery

#### Tidak Bisa Memanfaatkan Cache secara Penuh
MySQL dapat men-cache hasil subquery yang **tidak berkorelasi**, tetapi subquery berkorelasi **tidak bisa di-cache** karena nilainya berubah di setiap baris. Setiap eksekusi subquery diperlakukan sebagai query baru.

#### Verifikasi dengan EXPLAIN

Jalankan `EXPLAIN` pada query bermasalah:

```sql
EXPLAIN
SELECT
    p.name,
    p.price,
    (SELECT COUNT(*) FROM inv_product_map AS ipm WHERE ipm.pid = p.id) AS total_bought,
    (SELECT SUM(ipm.price) FROM inv_product_map AS ipm WHERE ipm.pid = p.id) AS total_amount
FROM product AS p;
```

Output khas yang akan Anda lihat:

```
+----+--------------------+------------------+------+---------------+---------+--------+--------+------+-------------+
| id | select_type        | table            | type | possible_keys | key     | key_len | ref   | rows | Extra       |
+----+--------------------+------------------+------+---------------+---------+--------+--------+------+-------------+
|  1 | PRIMARY            | p                | ALL  | NULL          | NULL    | NULL   | NULL  | 1000 | NULL        |
|  2 | DEPENDENT SUBQUERY | ipm              | ref  | idx_pid       | idx_pid | 4      | p.id  |   10 | Using index |
|  3 | DEPENDENT SUBQUERY | ipm              | ref  | idx_pid       | idx_pid | 4      | p.id  |   10 | Using index |
+----+--------------------+------------------+------+---------------+---------+--------+--------+------+-------------+
```

Perhatikan kolom **`select_type`**:
- `PRIMARY` = query utama (berjalan 1 kali)
- **`DEPENDENT SUBQUERY`** = subquery berkorelasi yang dieksekusi **berulang** untuk setiap baris query utama

Semakin banyak baris `PRIMARY`, semakin banyak kali `DEPENDENT SUBQUERY` dieksekusi.

---

## 4. Solusi: Rewrite dengan LEFT JOIN + GROUP BY

### 4.1 Query yang Dioptimalkan

```sql
SELECT
    p.name,
    p.price,
    COUNT(ipm.id)             AS total_bought,
    COALESCE(SUM(ipm.price), 0) AS total_amount
FROM product AS p
LEFT JOIN inv_product_map AS ipm ON ipm.pid = p.id
GROUP BY p.id, p.name, p.price;
```

### 4.2 Cara MySQL Mengeksekusinya

Berbeda dengan subquery berkorelasi, query ini dieksekusi dalam **satu kali pass**:

```
1. MySQL melakukan JOIN antara tabel product dan inv_product_map satu kali
2. Untuk setiap grup (p.id), MySQL menghitung COUNT dan SUM sekaligus
3. Hasilnya dikembalikan dalam satu set hasil
```

| Jumlah baris di `product` | Total eksekusi SQL |
|:---:|:---:|
| 100 | **1** |
| 1.000 | **1** |
| 10.000 | **1** |
| 100.000 | **1** |

Tidak peduli seberapa besar tabelnya, query ini **selalu hanya 1 operasi**.

### 4.3 Penjelasan Setiap Klausa

#### `LEFT JOIN` — Bukan `INNER JOIN`

```sql
LEFT JOIN inv_product_map AS ipm ON ipm.pid = p.id
```

Gunakan `LEFT JOIN` agar produk yang **belum pernah dibeli** tetap muncul di hasil dengan nilai `NULL` (yang kemudian dikonversi menjadi `0` oleh `COALESCE`).

Jika menggunakan `INNER JOIN`, produk tanpa transaksi akan hilang dari hasil — berbeda dengan perilaku subquery berkorelasi yang akan mengembalikan `0` untuk produk tersebut.

#### `GROUP BY` — Mengelompokkan per Produk

```sql
GROUP BY p.id, p.name, p.price
```

`GROUP BY` memastikan bahwa fungsi agregat `COUNT` dan `SUM` dihitung per produk. Sertakan semua kolom non-agregat yang ada di `SELECT` dalam klausa `GROUP BY` agar kompatibel dengan semua konfigurasi MySQL (termasuk mode `ONLY_FULL_GROUP_BY`).

> **Catatan:** Pada MySQL 5.7.5+, karena `p.id` adalah `PRIMARY KEY`, secara teknis cukup menulis `GROUP BY p.id` saja. Namun, menyertakan semua kolom yang di-`SELECT` lebih eksplisit dan portabel.

#### `COUNT(ipm.id)` — Bukan `COUNT(*)`

```sql
COUNT(ipm.id) AS total_bought
```

`COUNT(ipm.id)` hanya menghitung baris di mana `ipm.id` **tidak NULL**. Ini penting karena `LEFT JOIN` pada produk tanpa transaksi menghasilkan baris dengan semua kolom `ipm` bernilai `NULL`. Dengan `COUNT(ipm.id)`, produk tanpa transaksi akan mendapat nilai `0` — perilaku yang sama dengan subquery berkorelasi.

Jika menggunakan `COUNT(*)`, semua baris (termasuk baris NULL dari `LEFT JOIN`) akan dihitung, sehingga produk tanpa transaksi mendapat nilai `1` — **tidak sesuai**.

#### `COALESCE(SUM(ipm.price), 0)` — Tangani NULL

```sql
COALESCE(SUM(ipm.price), 0) AS total_amount
```

Ketika tidak ada baris yang cocok setelah `LEFT JOIN`, `SUM(ipm.price)` mengembalikan `NULL`. `COALESCE` mengkonversi `NULL` tersebut menjadi `0`, sehingga hasil lebih bersih dan mudah diproses oleh aplikasi.

---

## 5. Perbandingan Rencana Eksekusi (EXPLAIN)

### EXPLAIN — Query Lambat (Subquery Berkorelasi)

```sql
EXPLAIN
SELECT
    p.name, p.price,
    (SELECT COUNT(*) FROM inv_product_map AS ipm WHERE ipm.pid = p.id) AS total_bought,
    (SELECT SUM(ipm.price) FROM inv_product_map AS ipm WHERE ipm.pid = p.id) AS total_amount
FROM product AS p;
```

```
+----+--------------------+------------------+------+---------------+---------+--------+------+------+
| id | select_type        | table            | type | possible_keys | key     | rows   |Extra |
+----+--------------------+------------------+------+---------------+---------+--------+------+------+
|  1 | PRIMARY            | p                | ALL  | NULL          | NULL    | 1000   |      |
|  2 | DEPENDENT SUBQUERY | ipm              | ref  | idx_pid       | idx_pid | 10     |      |
|  3 | DEPENDENT SUBQUERY | ipm              | ref  | idx_pid       | idx_pid | 10     |      |
+----+--------------------+------------------+------+---------------+---------+--------+------+------+
```

**Yang perlu diperhatikan:**
- `select_type = DEPENDENT SUBQUERY` → dieksekusi berulang kali
- Ada **3 baris** dalam EXPLAIN → 1 query utama + 2 subquery
- `rows = 1000` pada baris PRIMARY berarti MySQL memproses 1.000 baris `product`, dan setiap baris memicu 2 eksekusi subquery

---

### EXPLAIN — Query Optimal (LEFT JOIN + GROUP BY)

```sql
EXPLAIN
SELECT
    p.name, p.price,
    COUNT(ipm.id) AS total_bought,
    COALESCE(SUM(ipm.price), 0) AS total_amount
FROM product AS p
LEFT JOIN inv_product_map AS ipm ON ipm.pid = p.id
GROUP BY p.id, p.name, p.price;
```

```
+----+-------------+-------+------+---------------+---------+--------+-------------+------+---------------------------------+
| id | select_type | table | type | possible_keys | key     | key_len| ref         | rows | Extra                           |
+----+-------------+-------+------+---------------+---------+--------+-------------+------+---------------------------------+
|  1 | SIMPLE      | p     | ALL  | NULL          | NULL    | NULL   | NULL        | 1000 | Using temporary; Using filesort |
|  1 | SIMPLE      | ipm   | ref  | idx_pid       | idx_pid | 4      | p.id        |   10 | NULL                            |
+----+-------------+-------+------+---------------+---------+--------+-------------+------+---------------------------------+
```

**Yang perlu diperhatikan:**
- `select_type = SIMPLE` → tidak ada subquery berkorelasi
- Hanya **2 baris** dalam EXPLAIN (kedua tabel di-JOIN sekaligus)
- `key = idx_pid` pada baris `ipm` → MySQL menggunakan indeks saat JOIN
- Kedua baris memiliki `id = 1` → bagian dari **satu operasi yang sama**

---

### Rangkuman Perbandingan

| Aspek | Subquery Berkorelasi | LEFT JOIN + GROUP BY |
|---|---|---|
| `select_type` | `DEPENDENT SUBQUERY` | `SIMPLE` |
| Jumlah eksekusi SQL | 2N + 1 | **1** |
| Bisa memanfaatkan cache hasil | ❌ Tidak | ✅ Ya |
| Skalabilitas pada data besar | ❌ Buruk | ✅ Baik |
| Produk tanpa transaksi muncul | ✅ Ya (nilai 0) | ✅ Ya (dengan `LEFT JOIN` + `COALESCE`) |

---

## 6. Rekomendasi Indeks

Indeks yang tepat adalah kunci agar query `LEFT JOIN + GROUP BY` bekerja secara optimal.

### Indeks Wajib

```sql
-- Indeks pada kolom yang digunakan sebagai kondisi JOIN
ALTER TABLE inv_product_map ADD INDEX idx_pid (pid);
```

Tanpa indeks ini, MySQL akan melakukan **full table scan** pada `inv_product_map` untuk setiap baris `product` — hampir sama buruknya dengan subquery berkorelasi.

### Verifikasi Indeks

```sql
SHOW INDEX FROM inv_product_map;
```

Pastikan kolom `pid` ada dalam daftar dengan nilai `Key_name` yang sesuai.

### Indeks Komposit (Opsional, untuk Performa Lebih Baik)

Jika query juga memiliki klausa `WHERE` pada `inv_product_map`, pertimbangkan indeks komposit:

```sql
-- Contoh: jika ada filter berdasarkan tanggal transaksi
ALTER TABLE inv_product_map ADD INDEX idx_pid_created (pid, created_at);
```

---

## 7. Contoh Kasus Tambahan

### 7.1 Agregat dengan Filter Kondisi

**Masalah — Subquery berkorelasi dengan WHERE:**

```sql
-- Hitung total pembelian hanya untuk transaksi di tahun 2024
SELECT
    p.name,
    (SELECT COUNT(*)
       FROM inv_product_map AS ipm
      WHERE ipm.pid = p.id
        AND YEAR(ipm.created_at) = 2024) AS total_bought_2024
FROM product AS p;
```

**Solusi — JOIN dengan filter:**

```sql
SELECT
    p.name,
    COUNT(ipm.id) AS total_bought_2024
FROM product AS p
LEFT JOIN inv_product_map AS ipm
       ON ipm.pid = p.id
      AND YEAR(ipm.created_at) = 2024   -- filter dipindah ke kondisi JOIN
GROUP BY p.id, p.name;
```

> **Perhatikan:** Kondisi filter (`YEAR(ipm.created_at) = 2024`) diletakkan di klausa `ON`, **bukan** di `WHERE`. Jika diletakkan di `WHERE`, baris produk tanpa transaksi di tahun 2024 akan ikut terfilter dan tidak muncul di hasil.

---

### 7.2 Beberapa Tabel Agregat Sekaligus

**Masalah — Banyak subquery:**

```sql
SELECT
    p.name,
    (SELECT COUNT(*) FROM inv_product_map ipm WHERE ipm.pid = p.id) AS total_bought,
    (SELECT SUM(ipm.price) FROM inv_product_map ipm WHERE ipm.pid = p.id) AS total_amount,
    (SELECT MAX(ipm.price) FROM inv_product_map ipm WHERE ipm.pid = p.id) AS max_price,
    (SELECT MIN(ipm.price) FROM inv_product_map ipm WHERE ipm.pid = p.id) AS min_price,
    (SELECT AVG(ipm.price) FROM inv_product_map ipm WHERE ipm.pid = p.id) AS avg_price
FROM product AS p;
-- 5 subquery berkorelasi → 5N + 1 operasi
```

**Solusi — Semua agregat sekaligus dalam satu JOIN:**

```sql
SELECT
    p.name,
    COUNT(ipm.id)               AS total_bought,
    COALESCE(SUM(ipm.price), 0) AS total_amount,
    MAX(ipm.price)              AS max_price,
    MIN(ipm.price)              AS min_price,
    AVG(ipm.price)              AS avg_price
FROM product AS p
LEFT JOIN inv_product_map AS ipm ON ipm.pid = p.id
GROUP BY p.id, p.name;
-- 1 operasi, semua agregat dihitung sekaligus
```

---

### 7.3 Agregat dari Dua Tabel Berbeda

Kadang kita perlu agregat dari dua tabel yang berbeda sekaligus.

```sql
-- Contoh: jumlah review dan total pembelian per produk
-- Tabel tambahan: product_reviews (pid, rating, ...)

SELECT
    p.name,
    COUNT(DISTINCT ipm.id)    AS total_bought,
    COUNT(DISTINCT rev.id)    AS total_reviews,
    AVG(rev.rating)           AS avg_rating
FROM product AS p
LEFT JOIN inv_product_map AS ipm ON ipm.pid = p.id
LEFT JOIN product_reviews  AS rev ON rev.pid = p.id
GROUP BY p.id, p.name;
```

> **Perhatian:** Ketika men-JOIN dua tabel yang masing-masing memiliki banyak baris per produk, bisa terjadi **duplikasi** yang memengaruhi hasil COUNT. Gunakan `COUNT(DISTINCT kolom)` untuk menghindari penghitungan ganda.

---

## 8. Latihan

### Soal 1

Diberikan tabel berikut:

```sql
CREATE TABLE customer (id INT, name VARCHAR(100));
CREATE TABLE orders   (id INT, customer_id INT, total DECIMAL(15,2), status VARCHAR(20));
```

Ubah query berikut ke bentuk yang dioptimalkan:

```sql
SELECT
    c.name,
    (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) AS total_orders,
    (SELECT SUM(o.total) FROM orders o WHERE o.customer_id = c.id) AS total_spent
FROM customer AS c;
```

<details>
<summary>💡 Lihat Jawaban</summary>

```sql
SELECT
    c.name,
    COUNT(o.id)               AS total_orders,
    COALESCE(SUM(o.total), 0) AS total_spent
FROM customer AS c
LEFT JOIN orders AS o ON o.customer_id = c.id
GROUP BY c.id, c.name;
```

</details>

---

### Soal 2

Dari query yang sudah dioptimalkan pada Soal 1, tambahkan kondisi:  
**Hanya hitung pesanan dengan `status = 'completed'`**, namun tetap tampilkan semua pelanggan (termasuk yang belum punya pesanan berstatus `completed`).

<details>
<summary>💡 Lihat Jawaban</summary>

```sql
SELECT
    c.name,
    COUNT(o.id)               AS total_completed_orders,
    COALESCE(SUM(o.total), 0) AS total_spent
FROM customer AS c
LEFT JOIN orders AS o
       ON o.customer_id = c.id
      AND o.status = 'completed'   -- filter di ON, bukan WHERE
GROUP BY c.id, c.name;
```

Jika filter diletakkan di `WHERE o.status = 'completed'`, pelanggan tanpa pesanan `completed` akan hilang dari hasil karena `NULL` tidak memenuhi kondisi `WHERE`.

</details>

---

### Soal 3

Identifikasi masalah pada query berikut dan tuliskan versi yang dioptimalkan:

```sql
SELECT
    p.name,
    (SELECT COUNT(*) FROM order_items oi
      INNER JOIN orders o ON o.id = oi.order_id
      WHERE oi.product_id = p.id AND o.status = 'paid') AS paid_count
FROM product p;
```

<details>
<summary>💡 Lihat Jawaban</summary>

**Masalah:** Subquery berkorelasi yang mereferensikan `p.id` dan melakukan JOIN internal — dieksekusi N kali.

**Solusi:**

```sql
SELECT
    p.name,
    COUNT(oi.id) AS paid_count
FROM product AS p
LEFT JOIN order_items AS oi ON oi.product_id = p.id
LEFT JOIN orders      AS o  ON o.id = oi.order_id AND o.status = 'paid'
GROUP BY p.id, p.name;
```

</details>

---

## 9. Ringkasan

### Aturan Utama

> ❌ **Hindari** menempatkan subquery berkorelasi yang mengandung fungsi agregat (`COUNT`, `SUM`, `AVG`, `MAX`, `MIN`) di dalam klausa `SELECT`.

> ✅ **Gunakan** `LEFT JOIN` + `GROUP BY` sebagai gantinya.

### Checklist Optimasi

- [ ] Periksa apakah ada `DEPENDENT SUBQUERY` pada output `EXPLAIN`
- [ ] Ganti setiap subquery agregat berkorelasi dengan `LEFT JOIN` + agregat di `SELECT`
- [ ] Gunakan `COUNT(kolom)` bukan `COUNT(*)` agar produk/entitas tanpa data terkait mendapat nilai `0`
- [ ] Bungkus `SUM()` dan `AVG()` dengan `COALESCE(..., 0)` untuk menghindari hasil `NULL`
- [ ] Pastikan kolom JOIN memiliki **indeks** (`INDEX idx_pid (pid)`)
- [ ] Letakkan filter kondisional di klausa `ON` (bukan `WHERE`) jika ingin tetap menampilkan baris induk yang tidak cocok
- [ ] Jalankan `EXPLAIN` sebelum dan sesudah optimasi untuk memverifikasi perbaikan

### Tabel Referensi Cepat

| Situasi | Rekomendasi |
|---|---|
| Hitung jumlah anak per induk | `COUNT(child.id)` dengan `LEFT JOIN` + `GROUP BY` |
| Jumlahkan nilai dari tabel anak | `COALESCE(SUM(child.amount), 0)` dengan `LEFT JOIN` + `GROUP BY` |
| Filter pada tabel anak tapi induk tetap muncul | Kondisi di klausa `ON`, bukan `WHERE` |
| Banyak agregat dari tabel yang sama | Satu `LEFT JOIN` dengan beberapa fungsi agregat sekaligus |
| Banyak agregat dari tabel berbeda | Beberapa `LEFT JOIN` + `COUNT(DISTINCT ...)` untuk menghindari duplikasi |
| Verifikasi rencana eksekusi | Gunakan `EXPLAIN` dan cari baris `DEPENDENT SUBQUERY` |
