<?php
namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/**
 * TrainingController
 *
 * Modul Training: Optimasi Query MySQL – Subquery Agregat (COUNT, SUM)
 *
 * Topik yang dibahas:
 *  1. Mengapa subquery berkorelasi dengan fungsi agregat (COUNT, SUM) tidak disarankan
 *  2. Solusi penulisan query yang optimal menggunakan LEFT JOIN + GROUP BY
 *
 * Referensi tabel yang digunakan:
 *  - products        : data produk (id, name, price, ...)
 *  - inv_product_map : peta transaksi produk (id, pid → FK ke products.id, price)
 */
class TrainingController extends Controller
{
    // -----------------------------------------------------------------------
    // Endpoint 1 – Informasi Modul
    // -----------------------------------------------------------------------

    /**
     * GET /training/query-optimization
     *
     * Mengembalikan penjelasan lengkap tentang topik optimasi query:
     * subquery berkorelasi vs. LEFT JOIN + GROUP BY.
     */
    public function info()
    {
        return response()->json([
            'success' => true,
            'modul'   => 'Optimasi Query MySQL – Subquery Agregat',
            'topik'   => [
                [
                    'no'    => 1,
                    'judul' => 'Kenapa subquery berkorelasi dengan agregat tidak disarankan?',
                    'penjelasan' => [
                        'Subquery berkorelasi (correlated subquery) adalah subquery yang merujuk kolom dari query luar (outer query).',
                        'Setiap subquery berkorelasi dieksekusi SATU KALI PER BARIS hasil query luar.',
                        'Jika tabel "products" memiliki N baris, maka setiap subquery (COUNT, SUM) akan dijalankan sebanyak N kali secara terpisah.',
                        'Dengan dua subquery (COUNT dan SUM), total eksekusi SQL menjadi 1 (query utama) + N (COUNT) + N (SUM) = 2N+1 operasi.',
                        'Pada dataset besar, pendekatan ini menyebabkan lonjakan I/O disk dan CPU yang signifikan, sehingga performa menurun drastis.',
                    ],
                    'contoh_query_lambat' => $this->getSlowQueryString(),
                ],
                [
                    'no'    => 2,
                    'judul' => 'Solusi: LEFT JOIN + GROUP BY',
                    'penjelasan' => [
                        'Gunakan LEFT JOIN untuk menghubungkan tabel products dengan inv_product_map sekali saja.',
                        'Gunakan GROUP BY pada kolom dari tabel products agar setiap produk hanya menghasilkan satu baris.',
                        'Gunakan fungsi agregat COUNT() dan SUM() langsung di SELECT setelah JOIN – MySQL menghitung nilai ini dalam satu kali pass data.',
                        'Dengan pendekatan ini, hanya ada SATU operasi scan/join, sehingga jauh lebih efisien dibanding subquery berkorelasi.',
                        'LEFT JOIN memastikan produk yang belum pernah dibeli tetap muncul (dengan nilai 0/NULL untuk agregat).',
                    ],
                    'contoh_query_optimal' => $this->getOptimizedQueryString(),
                ],
            ],
            'rekomendasi_indeks' => [
                'Pastikan kolom inv_product_map.pid memiliki indeks (sudah dibuat di migration).',
                'Indeks pada products.id biasanya sudah ada sebagai PRIMARY KEY.',
                'Indeks yang tepat memungkinkan MySQL menggunakan index lookup saat melakukan JOIN, bukan full table scan.',
            ],
        ], 200);
    }

    // -----------------------------------------------------------------------
    // Endpoint 2 – Eksekusi Query Lambat (Subquery Berkorelasi)
    // -----------------------------------------------------------------------

    /**
     * GET /training/query-optimization/slow
     *
     * Menjalankan query dengan subquery berkorelasi (COUNT + SUM) dan
     * mengembalikan hasil beserta waktu eksekusi agar dapat dibandingkan
     * dengan pendekatan yang dioptimalkan.
     */
    public function slowQuery()
    {
        $sql = $this->getSlowQueryString();

        $start   = microtime(true);
        $results = DB::select($sql);
        $elapsed = round((microtime(true) - $start) * 1000, 4);

        return response()->json([
            'success'       => true,
            'pendekatan'    => 'Subquery Berkorelasi (TIDAK DIREKOMENDASIKAN)',
            'query'         => $sql,
            'waktu_ms'      => $elapsed,
            'jumlah_baris'  => count($results),
            'data'          => $results,
            'catatan' => [
                'Query ini mengandung DUA subquery berkorelasi.',
                'Setiap subquery dijalankan sebanyak jumlah baris pada tabel products.',
                'Semakin besar tabel, semakin lambat query ini.',
            ],
        ], 200);
    }

    // -----------------------------------------------------------------------
    // Endpoint 3 – Eksekusi Query Optimal (LEFT JOIN + GROUP BY)
    // -----------------------------------------------------------------------

    /**
     * GET /training/query-optimization/optimized
     *
     * Menjalankan query yang dioptimalkan menggunakan LEFT JOIN + GROUP BY
     * dan mengembalikan hasil beserta waktu eksekusi.
     */
    public function optimizedQuery()
    {
        $sql = $this->getOptimizedQueryString();

        $start   = microtime(true);
        $results = DB::select($sql);
        $elapsed = round((microtime(true) - $start) * 1000, 4);

        return response()->json([
            'success'       => true,
            'pendekatan'    => 'LEFT JOIN + GROUP BY (DIREKOMENDASIKAN)',
            'query'         => $sql,
            'waktu_ms'      => $elapsed,
            'jumlah_baris'  => count($results),
            'data'          => $results,
            'catatan' => [
                'Query ini hanya melakukan SATU kali JOIN antara products dan inv_product_map.',
                'COUNT dan SUM dihitung dalam satu kali pass data setelah JOIN.',
                'Indeks pada inv_product_map.pid sangat membantu performa query ini.',
            ],
        ], 200);
    }

    // -----------------------------------------------------------------------
    // Endpoint 4 – EXPLAIN Query Lambat
    // -----------------------------------------------------------------------

    /**
     * GET /training/query-optimization/explain-slow
     *
     * Mengembalikan output EXPLAIN untuk query subquery berkorelasi.
     * Gunakan ini untuk melihat rencana eksekusi MySQL dan memahami
     * mengapa query tersebut tidak efisien.
     */
    public function explainSlow()
    {
        $sql    = 'EXPLAIN ' . $this->getSlowQueryString();
        $result = DB::select($sql);

        return response()->json([
            'success'    => true,
            'pendekatan' => 'EXPLAIN – Subquery Berkorelasi',
            'query'      => $this->getSlowQueryString(),
            'explain'    => $result,
            'cara_baca'  => [
                'Perhatikan kolom "select_type": nilai "DEPENDENT SUBQUERY" menandakan subquery berkorelasi yang dieksekusi berulang.',
                'Perhatikan kolom "type": nilai "ALL" berarti full table scan – sangat tidak efisien untuk tabel besar.',
                'Kolom "rows" menunjukkan perkiraan jumlah baris yang diperiksa per eksekusi.',
                'Semakin banyak baris dengan select_type = DEPENDENT SUBQUERY, semakin berat query tersebut.',
            ],
        ], 200);
    }

    // -----------------------------------------------------------------------
    // Endpoint 5 – EXPLAIN Query Optimal
    // -----------------------------------------------------------------------

    /**
     * GET /training/query-optimization/explain-optimized
     *
     * Mengembalikan output EXPLAIN untuk query LEFT JOIN + GROUP BY.
     * Bandingkan dengan EXPLAIN query lambat untuk melihat perbedaannya.
     */
    public function explainOptimized()
    {
        $sql    = 'EXPLAIN ' . $this->getOptimizedQueryString();
        $result = DB::select($sql);

        return response()->json([
            'success'    => true,
            'pendekatan' => 'EXPLAIN – LEFT JOIN + GROUP BY',
            'query'      => $this->getOptimizedQueryString(),
            'explain'    => $result,
            'cara_baca'  => [
                'Perhatikan kolom "select_type": idealnya hanya "SIMPLE" – tidak ada subquery berkorelasi.',
                'Perhatikan kolom "type": nilai "ref" atau "eq_ref" menandakan penggunaan indeks – jauh lebih efisien.',
                'Kolom "key" yang terisi menunjukkan indeks yang digunakan MySQL untuk JOIN.',
                'Bandingkan kolom "rows" dengan hasil EXPLAIN query lambat – seharusnya jauh lebih sedikit.',
            ],
        ], 200);
    }

    // -----------------------------------------------------------------------
    // Endpoint 6 – Perbandingan Langsung (Benchmark)
    // -----------------------------------------------------------------------

    /**
     * GET /training/query-optimization/compare
     *
     * Menjalankan kedua query secara berurutan dan mengembalikan
     * perbandingan waktu eksekusi serta hasil masing-masing pendekatan.
     */
    public function compare()
    {
        // Jalankan query lambat
        $slowSql   = $this->getSlowQueryString();
        $slowStart = microtime(true);
        $slowData  = DB::select($slowSql);
        $slowTime  = round((microtime(true) - $slowStart) * 1000, 4);

        // Jalankan query optimal
        $optimizedSql   = $this->getOptimizedQueryString();
        $optimizedStart = microtime(true);
        $optimizedData  = DB::select($optimizedSql);
        $optimizedTime  = round((microtime(true) - $optimizedStart) * 1000, 4);

        $selisih      = round($slowTime - $optimizedTime, 4);
        $persenHemat  = $slowTime > 0 ? round((($slowTime - $optimizedTime) / $slowTime) * 100, 2) : 0;

        return response()->json([
            'success' => true,
            'judul'   => 'Perbandingan Performa Query',
            'hasil_perbandingan' => [
                'query_lambat' => [
                    'label'        => 'Subquery Berkorelasi (TIDAK DIREKOMENDASIKAN)',
                    'query'        => $slowSql,
                    'waktu_ms'     => $slowTime,
                    'jumlah_baris' => count($slowData),
                ],
                'query_optimal' => [
                    'label'        => 'LEFT JOIN + GROUP BY (DIREKOMENDASIKAN)',
                    'query'        => $optimizedSql,
                    'waktu_ms'     => $optimizedTime,
                    'jumlah_baris' => count($optimizedData),
                ],
            ],
            'kesimpulan' => [
                'selisih_waktu_ms'   => $selisih,
                'penghematan_persen' => $persenHemat . '%',
                'catatan'            => $selisih >= 0
                    ? 'Query LEFT JOIN + GROUP BY lebih cepat ' . $selisih . ' ms (' . $persenHemat . '%) dibanding subquery berkorelasi.'
                    : 'Pada dataset kecil perbedaan mungkin belum signifikan. Perbedaan nyata terlihat pada tabel dengan ribuan hingga jutaan baris.',
            ],
            'panduan' => [
                '1. Hindari subquery berkorelasi di dalam SELECT untuk agregasi (COUNT, SUM, AVG, MAX, MIN).',
                '2. Gunakan LEFT JOIN + GROUP BY sebagai gantinya.',
                '3. Pastikan kolom yang digunakan dalam JOIN (inv_product_map.pid) memiliki indeks.',
                '4. Gunakan EXPLAIN untuk memeriksa rencana eksekusi query sebelum menjalankan di produksi.',
                '5. Uji performa pada dataset yang representatif (minimal puluhan ribu baris) untuk melihat perbedaan nyata.',
            ],
        ], 200);
    }

    // -----------------------------------------------------------------------
    // Helper – String Query
    // -----------------------------------------------------------------------

    /**
     * Query lambat: dua subquery berkorelasi untuk COUNT dan SUM.
     * Setiap subquery dieksekusi N kali (N = jumlah baris pada tabel products).
     */
    private function getSlowQueryString(): string
    {
        return
            'SELECT p.name, p.price, ' .
            '(SELECT COUNT(*) FROM inv_product_map AS ipm WHERE ipm.pid = p.id) AS total_bought, ' .
            '(SELECT SUM(ipm.price) FROM inv_product_map AS ipm WHERE ipm.pid = p.id) AS total_amount ' .
            'FROM products AS p';
    }

    /**
     * Query optimal: satu LEFT JOIN + GROUP BY.
     * MySQL melakukan satu kali JOIN dan menghitung agregat dalam satu pass.
     * COALESCE memastikan produk tanpa transaksi menampilkan 0 bukan NULL.
     *
     * GROUP BY menyertakan p.id, p.name, dan p.price agar kompatibel dengan
     * semua versi MySQL (termasuk mode ONLY_FULL_GROUP_BY). Pada MySQL 5.7.5+,
     * karena p.id adalah PRIMARY KEY, cukup GROUP BY p.id saja – tetapi menulis
     * semua kolom non-agregat lebih eksplisit dan portabel.
     */
    private function getOptimizedQueryString(): string
    {
        return
            'SELECT p.name, p.price, ' .
            'COUNT(ipm.id) AS total_bought, ' .
            'COALESCE(SUM(ipm.price), 0) AS total_amount ' .
            'FROM products AS p ' .
            'LEFT JOIN inv_product_map AS ipm ON ipm.pid = p.id ' .
            'GROUP BY p.id, p.name, p.price';
    }
}
