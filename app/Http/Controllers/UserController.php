<?php
namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class UserController extends Controller {
  public function mycart(Request $request) {
    $invoiceList = DB::table('invoices')
                     ->leftJoin('products', 'products.id', '=', 'invoices.id_product')
                     ->leftJoin('brands', 'brands.id', '=', 'products.id_brand')
                     ->select(DB::raw('CONCAT(brands.name, " ", products.name) AS product_name'), 'invoices.amount', 'products.price AS each_price', DB::raw('invoices.amount*products.price AS total_price'))
                     ->where(['invoices.id_user' => \Auth::id(), 'invoices.has_bought' => true])
                     ->paginate(10);

    return response()->json([
      'success' => true,
      'data' => $invoiceList
    ], 200);
  }

  public function search(Request $request) {
    //print_r($request->all());
    $invoiceList = DB::table('invoices')
    ->leftJoin('products', 'products.id', '=', 'invoices.id_product')
    ->leftJoin('brands', 'brands.id', '=', 'products.id_brand')
    ->select(DB::raw('CONCAT(brands.name, " ", products.name) AS product_name'), 'invoices.amount', 'products.price AS each_price', DB::raw('invoices.amount*products.price AS total_price'))
    ->where('invoices.id_user', \Auth::id())
    ->where('invoices.has_bought', true)
    ->where(function($query) use ($request){
      $query->where('products.name', 'LIKE', '%'.$request->keyword.'%')
      ->orWhere('brands.name', 'LIKE', '%'.$request->keyword.'%');
    })
    ->paginate(10);

    if($invoiceList->count() < 1) {
      return response()->json([
        'success' => false,
        'data' => "Data not found"
      ], 404);  
    }

    return response()->json([
      'success' => true,
      'data' => $invoiceList
    ], 200);
  }
}