<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Invoice;

class ShopController extends Controller {
  public function index() {
    $query = DB::table('products')
               ->leftJoin('brands', 'brands.id', '=', 'products.id_brand')
               ->leftJoin('types', 'types.id', '=', 'products.id_type')
               ->select('products.id', DB::raw('CONCAT(brands.name, " ", products.name) AS product_name'), DB::raw('types.name AS type_name'), 'price')
               ->orderBy('products.name')
               ->paginate(9);

    if($query->count() < 1) {
      return response()->json([
        'success' => false,
        'data' => 'No data'
      ]);
    }

    return response()->json([
      'success' => true,
      'data' => $query
    ]);
  }

  public function show($id) {
    $product = DB::table('products')
                 ->leftJoin('brands', 'brands.id', '=', 'products.id_brand')
                 ->leftJoin('types', 'types.id', '=', 'products.id_type')
                 ->select('products.id', DB::raw('CONCAT(brands.name, " ", products.name) AS product_name'), DB::raw('types.name AS type_name'), 'price')
                 ->where('products.id', $id)
                 ->first();
    
    if(!$product) {
      return response()->json([
        'success' => false,
        'data' => 'Data not found'
      ]);
    }

    return response()->json([
      'success' => true,
      'data' => $product
    ]);
  }

  public function buy(Request $request) {
    $buy = DB::table('invoices')->insert([
      'id_user' => \Auth::id(),
      'id_product' => $request->id_product,
      'amount' => $request->amount
    ]);

    if(!$buy) {
      return response()->json([
        'success' => false,
        'data' => 'Cannot process your transaction'
      ]);
    }

    return response()->json([
      'success' => true,
      'data' => 'Your transaction has been added to cart'
    ]);
  }

  public function cart() {
    $cart = DB::table('invoices')
              ->leftJoin('users', 'users.id', '=', 'invoices.id_user')
              ->leftJoin('products', 'products.id', '=', 'invoices.id_product')
              ->select('invoices.id', 'products.name AS product_name', 'invoices.amount', 'products.price AS each_price', DB::raw('invoices.amount*products.price AS total_price'))
              ->where(array('invoices.id_user' => \Auth::id(), 'invoices.has_bought' => false))
              ->orderBy('products.name', 'ASC')
              ->paginate(10);
    
    if(!$cart) {
      return response()->json([
        'success' => false,
        'data' => 'Data not found'
      ]);
    }

    $total_price = DB::table('invoices')
                     ->leftJoin('products', 'products.id', '=', 'invoices.id_product')
                     ->select(DB::raw('SUM(invoices.amount*products.price) AS total_price'))
                     ->where(array('invoices.id_user' => \Auth::id(), 'invoices.has_bought' => false))
                     ->get();

    return response()->json([
      'success' => true,
      'data' => ['data' => $cart, 'total' => $total_price]
    ]);
  }

  public function historyTransaction() {
    $history = DB::table('invoices')
                 ->leftJoin('users', 'users.id', '=', 'invoices.id_user')
                 ->leftJoin('products', 'products.id', '=', 'invoices.id_product')
                 ->select('invoices.id', 'products.name AS product_name', 'invoices.amount', 'products.price AS each_price', DB::raw('invoices.amount*products.price AS total_price'))
                 ->where(['invoices.id_user' => \Auth::id(), 'invoices.has_bought' => true])
                 ->orderBy('products.name', 'ASC')
                 ->paginate(10);
    
    return response()->json([
      'success' => true,
      'data' => $history
    ]);
  }

  public function payment() {
    $pay = DB::table('invoices')->where(array('invoices.id_user' => \Auth::id(), 'invoices.has_bought' => false))->update(array('invoices.has_bought' => true));

    if(!$pay) {
      return response()->json([
        'success' => false,
        'data' => 'Cannot do payment!'
      ]);
    }

    return response()->json([
      'success' => true,
      'data' => 'Your payment has been completed!'
    ]);
  }

  public function filter(Request $request) {
    $query = DB::table('products')
               ->leftJoin('brands', 'brands.id', '=', 'products.id_brand')
               ->leftJoin('types', 'types.id', '=', 'products.id_type')
               ->select('products.id', DB::raw('COALESCE(brands.name, " ",products.name) AS product_name'), 'brands.name AS brand_name','products.name AS product_name_2', 'price')
               ->where('types.id', '=', $request->id)
               ->orderBy('products.name')
               ->paginate(9);

    if($query->count() < 1) {
      return response()->json([
        'success' => false,
        'data' => 'Data not found'
      ], 404);
    }

    return response()->json([
      'success' => true,
      'data' => $query
    ], 200);
  }
}