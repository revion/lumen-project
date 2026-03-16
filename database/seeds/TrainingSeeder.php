<?php

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TrainingSeeder extends Seeder
{
    /**
     * Seed data untuk modul training optimasi query MySQL.
     * Membuat contoh data pada tabel products dan inv_product_map
     * agar query pelatihan dapat dijalankan dan dibandingkan.
     *
     * @return void
     */
    public function run()
    {
        // Pastikan tabel inv_product_map kosong sebelum seeding
        // Hanya truncate di lingkungan non-produksi untuk menghindari kehilangan data nyata
        if (app()->environment(['local', 'testing', 'staging'])) {
            DB::table('inv_product_map')->truncate();
        }

        // Ambil semua produk yang ada
        $products = DB::table('products')->pluck('id')->toArray();

        if (empty($products)) {
            $this->command->warn('Tabel products kosong. Tambahkan data produk terlebih dahulu sebelum menjalankan TrainingSeeder.');
            return;
        }

        // Buat data transaksi contoh untuk setiap produk
        $records = [];
        $now = now();

        foreach ($products as $productId) {
            // Setiap produk mendapat 5-15 transaksi secara acak
            $transactionCount = rand(5, 15);
            for ($i = 0; $i < $transactionCount; $i++) {
                $records[] = [
                    'pid'        => $productId,
                    'price'      => rand(10000, 500000),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Insert dalam batch agar lebih efisien
        foreach (array_chunk($records, 100) as $chunk) {
            DB::table('inv_product_map')->insert($chunk);
        }

        $this->command->info('TrainingSeeder berhasil: ' . count($records) . ' data transaksi dibuat untuk ' . count($products) . ' produk.');
    }
}
