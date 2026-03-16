<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateInvProductMapTable extends Migration
{
    /**
     * Run the migrations.
     * Tabel ini memetakan setiap transaksi pembelian produk.
     * Digunakan sebagai bahan latihan optimasi query MySQL (subquery agregat vs JOIN+GROUP BY).
     *
     * @return void
     */
    public function up()
    {
        Schema::create('inv_product_map', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('pid')->comment('Foreign key ke tabel products');
            $table->decimal('price', 15, 2)->comment('Harga produk pada saat transaksi');
            $table->timestamps();

            $table->foreign('pid')->references('id')->on('products')->onDelete('cascade');
            $table->index('pid');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('inv_product_map');
    }
}
