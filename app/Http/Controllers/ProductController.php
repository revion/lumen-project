<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Product;

class ProductController extends Controller {
  public function index() {
    $query = DB::table('products')
               ->leftJoin('brands', 'brands.id', '=', 'products.id_brand')
               ->leftJoin('types', 'types.id', '=', 'products.id_type')
               ->select('products.id', DB::raw('CONCAT(brands.name, " ", products.name) AS product_name'), DB::raw('types.name AS type_name'), 'stock', 'price')
               ->orderBy('products.name', 'asc')
               ->paginate(10);

    return response()->json([
      'success' => true,
      'data' => $query
    ], 200);
  }

  public function loadBrand() {
    $query = DB::table('brands')
              ->select('*')
              ->orderBy('name', 'asc')
              ->get();

    return response()->json([
      'success' => true,
      'data' => $query
    ], 200);
  }

  public function loadType() {
    $query = DB::table('types')
               ->select('*')
               ->orderBy('name', 'asc')
               ->get();

    return response()->json([
      'success' => true,
      'data' => $query
    ], 200);
  }

  public function create(Request $request) {
    $query = Product::create([
      'id_brand' => $request->input('id_brand'),
      'id_type' => $request->input('id_type'),
      'name' => $request->input('name'),
      'stock' => $request->input('stock'),
      'price' => $request->input('price')
    ]);

    return response()->json([
      'success' => true,
      'data' => "Product ".$query->name." added successfully"
    ], 200);
  }

  public function update(Request $request, $id) {
    $product = Product::where('id', $id)->first();

    if(!$product) {
      return response()->json([
        'success' => false,
        'data' => 'Data not found'
      ], 404);
    }

    $product->update([
      'id_brand' => $request->input('id_brand'),
      'id_type' => $request->input('id_type'),
      'name' => $request->input('name'),
      'stock' => $request->input('stock'),
      'price' => $request->input('price')
    ]);

    return response()->json([
      'success' => true,
      'data' => "Product ID {$id} information has been updated"
    ], 201);
  }

  public function delete($id) {
    $product = Product::where('id', $id)->first();

    if(!$product) {
      return response()->json([
        'success' => false,
        'data' => "Data not found"
      ], 404);
    }

    $product->delete();

    return response()->json([
      'success' => true,
      'data' => "Product ID {$id} has been deleted"
    ], 201);
  }

  public function search(Request $request) {
    $keyword = $request->input('keyword');

    $query = DB::table('products')
              ->leftJoin('brands', 'brands.id', '=', 'products.id_brand')
              ->leftJoin('types', 'types.id', '=', 'products.id_type')
              ->select('products.id', DB::raw('CONCAT(brands.name, " ", products.name) AS product_name'), DB::raw('types.name AS type_name'), 'stock', 'price')
              ->where('products.name', 'LIKE', '%'.$keyword.'%')
              ->orWhere('brands.name', 'LIKE', '%'.$keyword.'%')
              ->orderBy('products.name', 'ASC')
              ->paginate(10);

    if($query->count() < 1) {
      return response()->json([
        'success' => false,
        'data' => "No data with keyword: ".$keyword
      ], 404);
    }

    return response()->json([
      'success' => true,
      'data' => $query
    ], 200);
  }

  public function getproduct($id) {
    $product = Product::where('id', $id)->first();

    return response()->json([
      'success' => true,
      'data' => $product
    ], 200);
  }
}