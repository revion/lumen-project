# Modul Training: Optimasi Query MySQL — Subquery dengan Fungsi Agregat

**Topik:** Optimasi Query MySQL — Subquery Berkorelasi vs Derived Table / CTE  
**Level:** Intermediate  
**Estimasi Waktu:** 60–90 menit

---

## Daftar Isi

1. [Pendahuluan](#1-pendahuluan)
2. [Studi Kasus: Query yang Bermasalah](#2-studi-kasus-query-yang-bermasalah)
3. [Mengapa Subquery Berkorelasi Tidak Disarankan](#3-mengapa-subquery-berkorelasi-tidak-disarankan)
4. [Solusi yang Direkomendasikan](#4-solusi-yang-direkomendasikan)
   - [4.1 Derived Table (Pre-Aggregate) — Paling Ringan](#41-derived-table-pre-aggregate--paling-ringan)
   - [4.2 CTE (MySQL 8.0+) — Alternatif Modern](#42-cte-mysql-80--alternatif-modern)
   - [4.3 LEFT JOIN + GROUP BY — Alternatif Lain](#43-left-join--group-by--alternatif-lain)
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
2. Solusi penulisan query yang mencapai tujuan yang sama namun jauh lebih efisien, termasuk alternatif yang **lebih ringan dari `GROUP BY`**

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

## 4. Solusi yang Direkomendasikan

Ada tiga pendekatan yang dapat digunakan. Ketiganya jauh lebih baik dari subquery berkorelasi, namun masing-masing memiliki perbedaan bobot eksekusi.

| Pendekatan | Klausa Kunci | `Using temporary` di EXPLAIN | Keterangan |
|---|---|:---:|---|
| **Derived Table** | `LEFT JOIN (SELECT … GROUP BY)` | ❌ Tidak | **Terbaik** — GROUP BY hanya pada tabel kecil agregat |
| **CTE** | `WITH … AS (SELECT … GROUP BY)` | ❌ Tidak | Sama dengan Derived Table, lebih mudah dibaca (MySQL 8.0+) |
| LEFT JOIN + GROUP BY | `LEFT JOIN … GROUP BY` pada query utama | ✅ Ya | Masih baik, namun lebih berat jika baris hasil besar |

---

### 4.1 Derived Table (Pre-Aggregate) — Paling Ringan

**Ide utama:** agregasi dilakukan **dulu** di dalam sebuah subquery di klausa `FROM` (disebut *derived table* atau *inline view*), menghasilkan satu baris per produk. Hasilnya baru di-`JOIN` ke tabel `product`. Dengan cara ini, query luar **tidak perlu `GROUP BY` sama sekali**.

```sql
SELECT
    p.name,
    p.price,
    COALESCE(agg.total_bought, 0) AS total_bought,
    COALESCE(agg.total_amount, 0) AS total_amount
FROM product AS p
LEFT JOIN (
    SELECT
        pid,
        COUNT(*)   AS total_bought,
        SUM(price) AS total_amount
    FROM inv_product_map
    GROUP BY pid              -- GROUP BY hanya di sini, pada satu tabel kecil
) AS agg ON agg.pid = p.id;
```

#### Cara MySQL Mengeksekusinya

```
1. MySQL menjalankan derived table SATU KALI:
   → Baca seluruh inv_product_map, GROUP BY pid
   → Hasilnya: 1 baris per produk (tabel kecil "agg")

2. MySQL men-JOIN tabel product dengan "agg" (1:1 atau 1:0):
   → Tidak ada GROUP BY pada query luar
   → Tidak ada sorting/temporary table pada query luar
```

#### Kenapa Lebih Ringan dari `LEFT JOIN + GROUP BY` pada Query Utama?

Saat menggunakan `LEFT JOIN + GROUP BY` langsung di query utama, MySQL harus:

1. Membangun hasil JOIN terlebih dahulu (bisa ribuan baris: N produk × M transaksi per produk)
2. Baru men-sort dan mengelompokkan hasil JOIN yang besar itu → **`Using temporary; Using filesort`**

Dengan derived table, `GROUP BY` hanya berjalan pada `inv_product_map` saja — tabel tunggal yang lebih kecil — dan hasilnya sudah tereduksi menjadi **1 baris per produk** sebelum di-JOIN. Query luar tinggal melakukan simple 1:1 lookup.

#### Penjelasan Klausa `COALESCE`

```sql
COALESCE(agg.total_bought, 0) AS total_bought
COALESCE(agg.total_amount, 0) AS total_amount
```

Jika sebuah produk tidak memiliki transaksi sama sekali, `LEFT JOIN` menghasilkan `NULL` untuk semua kolom `agg`. `COALESCE` mengubah `NULL` tersebut menjadi `0`.

> Perhatikan: di dalam derived table kita menggunakan `COUNT(*)` karena kita sudah memfilter pada tabel `inv_product_map` saja — tidak ada baris NULL palsu akibat `LEFT JOIN` di sini.

---

### 4.2 CTE (MySQL 8.0+) — Alternatif Modern

**CTE** (*Common Table Expression*) menggunakan klausa `WITH` untuk mendefinisikan hasil sementara bernama. Secara logis dan performa, CTE identik dengan derived table — hanya berbeda dalam keterbacaan.

```sql
WITH agg AS (
    SELECT
        pid,
        COUNT(*)   AS total_bought,
        SUM(price) AS total_amount
    FROM inv_product_map
    GROUP BY pid
)
SELECT
    p.name,
    p.price,
    COALESCE(agg.total_bought, 0) AS total_bought,
    COALESCE(agg.total_amount, 0) AS total_amount
FROM product AS p
LEFT JOIN agg ON agg.pid = p.id;
```

**Keunggulan CTE dibanding Derived Table:**
- Lebih mudah dibaca dan di-maintain, terutama bila ada banyak agregat
- CTE bisa dirujuk berkali-kali jika diperlukan (menghindari pengulangan kode)
- Lebih mudah di-debug: bisa dijalankan bagian `WITH`-nya secara terpisah

**Syarat:** Membutuhkan **MySQL 8.0+** atau MariaDB 10.2.1+. Untuk MySQL 5.7 ke bawah, gunakan derived table (4.1).

---

### 4.3 LEFT JOIN + GROUP BY — Alternatif Lain

Pendekatan ini tetap valid, terutama bila sudah ada indeks yang baik pada kolom JOIN. Namun `GROUP BY` bekerja pada **hasil JOIN** (bisa berisi banyak baris), sehingga MySQL sering menghasilkan `Using temporary; Using filesort` pada query luar.

```sql
SELECT
    p.name,
    p.price,
    COUNT(ipm.id)               AS total_bought,
    COALESCE(SUM(ipm.price), 0) AS total_amount
FROM product AS p
LEFT JOIN inv_product_map AS ipm ON ipm.pid = p.id
GROUP BY p.id, p.name, p.price;
```

**Kapan tetap memilih pendekatan ini:**
- Versi MySQL < 5.7 (derived table tetap didukung, tetapi CTE tidak)
- Query sangat sederhana (hanya 2 tabel, satu tingkat GROUP BY)
- Tim sudah familiar dan indeks sudah lengkap

**Kapan lebih baik beralih ke Derived Table / CTE:**
- Banyak kolom di `GROUP BY` (kinerja sort semakin berat)
- Hasil JOIN besar (banyak produk × banyak transaksi per produk)
- Ada beberapa agregat berbeda dari tabel yang sama

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

### EXPLAIN — Derived Table (Pendekatan Terbaik)

```sql
EXPLAIN
SELECT
    p.name, p.price,
    COALESCE(agg.total_bought, 0) AS total_bought,
    COALESCE(agg.total_amount, 0) AS total_amount
FROM product AS p
LEFT JOIN (
    SELECT pid, COUNT(*) AS total_bought, SUM(price) AS total_amount
    FROM inv_product_map
    GROUP BY pid
) AS agg ON agg.pid = p.id;
```

```
+----+-------------+------------------+------+---------------+---------+--------+--------+------+--------------------+
| id | select_type | table            | type | possible_keys | key     | key_len| ref    | rows | Extra              |
+----+-------------+------------------+------+---------------+---------+--------+--------+------+--------------------+
|  1 | PRIMARY     | p                | ALL  | NULL          | NULL    | NULL   | NULL   | 1000 | NULL               |
|  1 | PRIMARY     | <derived2>       | ref  | <auto_key0>   | auto0   | 4      | p.id   |   10 | NULL               |
|  2 | DERIVED     | inv_product_map  | ALL  | idx_pid       | idx_pid | NULL   | NULL   | 5000 | Using index        |
+----+-------------+------------------+------+---------------+---------+--------+--------+------+--------------------+
```

**Yang perlu diperhatikan:**
- `select_type = DERIVED` → subquery di `FROM` dieksekusi **satu kali**, hasilnya disimpan sebagai tabel sementara kecil
- `select_type = PRIMARY` pada baris `p` dan `<derived2>` → keduanya bagian dari query luar yang sederhana
- **Tidak ada** `DEPENDENT SUBQUERY` → tidak ada eksekusi berulang
- Tidak ada `Using temporary; Using filesort` pada query luar (baris `p`) → **tidak ada sort/grouping pada hasil JOIN**
- `GROUP BY` hanya terjadi di dalam derived table (baris `inv_product_map`), bukan pada hasil JOIN yang besar

---

### EXPLAIN — LEFT JOIN + GROUP BY (Alternatif Lain)

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
- `select_type = SIMPLE` → tidak ada subquery berkorelasi ✅
- Hanya **2 baris** dalam EXPLAIN (kedua tabel di-JOIN sekaligus) ✅
- Namun: **`Using temporary; Using filesort`** pada baris `p` → MySQL membangun tabel sementara dari seluruh hasil JOIN, lalu men-sort-nya untuk GROUP BY ⚠️

---

### Rangkuman Perbandingan

| Aspek | Subquery Berkorelasi | Derived Table / CTE | LEFT JOIN + GROUP BY |
|---|---|---|---|
| `select_type` | `DEPENDENT SUBQUERY` | `DERIVED` + `PRIMARY` | `SIMPLE` |
| Jumlah eksekusi SQL | 2N + 1 | **1** | **1** |
| `Using temporary; Using filesort` (outer) | ❌ N/A | ✅ Tidak ada | ⚠️ Ada |
| GROUP BY diterapkan pada | N/A | Tabel kecil (pre-agregat) | Hasil JOIN yang besar |
| Bisa memanfaatkan cache hasil | ❌ Tidak | ✅ Ya | ✅ Ya |
| Skalabilitas pada data besar | ❌ Buruk | ✅ Terbaik | ✅ Baik |
| Produk tanpa transaksi muncul | ✅ Ya (nilai 0) | ✅ Ya (dengan `COALESCE`) | ✅ Ya (dengan `COALESCE`) |
| Versi MySQL minimum | Semua | 5.x (derived table) / 8.0+ (CTE) | Semua |

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

**Solusi — Derived Table dengan filter di dalam pre-agregat:**

```sql
SELECT
    p.name,
    COALESCE(agg.total_bought_2024, 0) AS total_bought_2024
FROM product AS p
LEFT JOIN (
    SELECT
        pid,
        COUNT(*) AS total_bought_2024
    FROM inv_product_map
    WHERE YEAR(created_at) = 2024   -- filter dilakukan sebelum agregasi
    GROUP BY pid
) AS agg ON agg.pid = p.id;
```

Filter diletakkan di dalam derived table (klausa `WHERE` di dalam subquery `FROM`), bukan di query luar. Produk yang tidak memiliki transaksi di tahun 2024 tetap muncul karena `LEFT JOIN` pada query luar.

**Alternatif dengan CTE (MySQL 8.0+):**

```sql
WITH agg AS (
    SELECT pid, COUNT(*) AS total_bought_2024
    FROM inv_product_map
    WHERE YEAR(created_at) = 2024
    GROUP BY pid
)
SELECT
    p.name,
    COALESCE(agg.total_bought_2024, 0) AS total_bought_2024
FROM product AS p
LEFT JOIN agg ON agg.pid = p.id;
```

---

### 7.2 Beberapa Agregat Sekaligus

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

**Solusi — Semua agregat dalam satu derived table:**

```sql
SELECT
    p.name,
    COALESCE(agg.total_bought, 0) AS total_bought,
    COALESCE(agg.total_amount, 0) AS total_amount,
    agg.max_price,
    agg.min_price,
    agg.avg_price
FROM product AS p
LEFT JOIN (
    SELECT
        pid,
        COUNT(*)   AS total_bought,
        SUM(price) AS total_amount,
        MAX(price) AS max_price,
        MIN(price) AS min_price,
        AVG(price) AS avg_price
    FROM inv_product_map
    GROUP BY pid
) AS agg ON agg.pid = p.id;
-- 1 operasi, semua agregat dihitung sekaligus di derived table
```

Semua fungsi agregat dituliskan di dalam satu derived table — tidak ada `GROUP BY` pada query luar sama sekali.

---

### 7.3 Agregat dari Dua Tabel Berbeda

Ketika perlu agregat dari dua tabel yang berbeda sekaligus, buat **dua derived table terpisah** lalu gabungkan.

```sql
-- Contoh: jumlah review dan total pembelian per produk
-- Tabel tambahan: product_reviews (pid, rating, ...)

SELECT
    p.name,
    COALESCE(buy.total_bought, 0)  AS total_bought,
    COALESCE(rev.total_reviews, 0) AS total_reviews,
    rev.avg_rating
FROM product AS p
LEFT JOIN (
    SELECT pid, COUNT(*) AS total_bought
    FROM inv_product_map
    GROUP BY pid
) AS buy ON buy.pid = p.id
LEFT JOIN (
    SELECT pid, COUNT(*) AS total_reviews, AVG(rating) AS avg_rating
    FROM product_reviews
    GROUP BY pid
) AS rev ON rev.pid = p.id;
```

Keunggulan pendekatan ini dibanding satu `JOIN` besar + `GROUP BY`:
- Setiap derived table mereduksi datanya menjadi satu baris per produk sebelum di-JOIN
- Tidak ada risiko **duplikasi** baris akibat JOIN dua tabel multi-baris (masalah Cartesian partial)
- Tidak perlu `COUNT(DISTINCT ...)` seperti pada pendekatan JOIN langsung

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

**Pendekatan Derived Table (direkomendasikan):**

```sql
SELECT
    c.name,
    COALESCE(agg.total_orders, 0) AS total_orders,
    COALESCE(agg.total_spent, 0)  AS total_spent
FROM customer AS c
LEFT JOIN (
    SELECT customer_id, COUNT(*) AS total_orders, SUM(total) AS total_spent
    FROM orders
    GROUP BY customer_id
) AS agg ON agg.customer_id = c.id;
```

**Alternatif CTE (MySQL 8.0+):**

```sql
WITH agg AS (
    SELECT customer_id, COUNT(*) AS total_orders, SUM(total) AS total_spent
    FROM orders
    GROUP BY customer_id
)
SELECT
    c.name,
    COALESCE(agg.total_orders, 0) AS total_orders,
    COALESCE(agg.total_spent, 0)  AS total_spent
FROM customer AS c
LEFT JOIN agg ON agg.customer_id = c.id;
```

</details>

---

### Soal 2

Dari query yang sudah dioptimalkan pada Soal 1, tambahkan kondisi:  
**Hanya hitung pesanan dengan `status = 'completed'`**, namun tetap tampilkan semua pelanggan (termasuk yang belum punya pesanan berstatus `completed`).

<details>
<summary>💡 Lihat Jawaban</summary>

**Pendekatan Derived Table:**

```sql
SELECT
    c.name,
    COALESCE(agg.total_completed_orders, 0) AS total_completed_orders,
    COALESCE(agg.total_spent, 0)            AS total_spent
FROM customer AS c
LEFT JOIN (
    SELECT customer_id, COUNT(*) AS total_completed_orders, SUM(total) AS total_spent
    FROM orders
    WHERE status = 'completed'   -- filter di dalam derived table sebelum agregasi
    GROUP BY customer_id
) AS agg ON agg.customer_id = c.id;
```

Filter `status = 'completed'` diletakkan di dalam `WHERE` pada derived table. Pelanggan tanpa pesanan berstatus `completed` tetap muncul karena `LEFT JOIN` di query luar menghasilkan `NULL` untuk kolom `agg`, lalu `COALESCE` mengubahnya menjadi `0`.

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

**Solusi dengan Derived Table:**

```sql
SELECT
    p.name,
    COALESCE(agg.paid_count, 0) AS paid_count
FROM product AS p
LEFT JOIN (
    SELECT oi.product_id, COUNT(*) AS paid_count
    FROM order_items AS oi
    INNER JOIN orders AS o ON o.id = oi.order_id AND o.status = 'paid'
    GROUP BY oi.product_id
) AS agg ON agg.product_id = p.id;
```

Aggregasi `order_items` bersama kondisi `status = 'paid'` dilakukan **satu kali** di dalam derived table, menghasilkan satu baris per produk. Query luar hanya melakukan simple JOIN terhadap hasil kecil tersebut.

</details>

---

## 9. Ringkasan

### Aturan Utama

> ❌ **Hindari** menempatkan subquery berkorelasi yang mengandung fungsi agregat (`COUNT`, `SUM`, `AVG`, `MAX`, `MIN`) di dalam klausa `SELECT`.

> ✅ **Gunakan Derived Table** — pre-agregasi di dalam subquery `FROM`, lalu `LEFT JOIN` hasilnya ke tabel utama.  
> ✅ **Gunakan CTE** (MySQL 8.0+) sebagai alternatif Derived Table yang lebih mudah dibaca.  
> ✅ **LEFT JOIN + GROUP BY** masih merupakan pilihan valid, namun lebih berat karena `GROUP BY` diterapkan pada hasil JOIN yang lebih besar.

### Checklist Optimasi

- [ ] Periksa apakah ada `DEPENDENT SUBQUERY` pada output `EXPLAIN` → ganti dengan Derived Table atau CTE
- [ ] Periksa apakah ada `Using temporary; Using filesort` pada query luar → pertimbangkan Derived Table / CTE
- [ ] Letakkan `GROUP BY` hanya di dalam derived table (pada tabel anak), **bukan** di query luar
- [ ] Gunakan `LEFT JOIN` agar entitas induk tanpa data anak tetap muncul
- [ ] Bungkus kolom dari derived table dengan `COALESCE(..., 0)` untuk menangani `NULL`
- [ ] Pastikan kolom JOIN memiliki **indeks** (`INDEX idx_pid (pid)`)
- [ ] Untuk filter kondisional, letakkan klausa `WHERE` di dalam derived table (bukan di query luar)
- [ ] Untuk dua sumber agregat berbeda, gunakan **dua derived table terpisah** untuk menghindari duplikasi
- [ ] Jalankan `EXPLAIN` sebelum dan sesudah optimasi untuk memverifikasi perbaikan

### Tabel Referensi Cepat

| Situasi | Pendekatan yang Direkomendasikan |
|---|---|
| Hitung jumlah anak per induk | Derived Table: `COUNT(*) … GROUP BY` di dalam subquery `FROM` |
| Jumlahkan nilai dari tabel anak | Derived Table: `SUM(amount) … GROUP BY` + `COALESCE` di luar |
| Banyak agregat dari tabel yang sama | Satu Derived Table dengan beberapa fungsi agregat |
| Banyak agregat dari tabel berbeda | Dua Derived Table terpisah, JOIN secara berurutan |
| Filter kondisional (masih ingin semua induk muncul) | `WHERE` di dalam Derived Table, `LEFT JOIN` di luar |
| Perlu keterbacaan tinggi, MySQL 8.0+ | CTE (`WITH … AS (SELECT … GROUP BY)`) |
| MySQL < 5.7 atau query sederhana | LEFT JOIN + GROUP BY (masih valid) |
| Verifikasi rencana eksekusi | `EXPLAIN` — cari `DEPENDENT SUBQUERY` dan `Using temporary; Using filesort` |
