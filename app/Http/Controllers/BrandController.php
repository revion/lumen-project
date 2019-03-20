<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Brand;

class BrandController extends Controller {
  public function index() {
    $query = Brand::orderBy('name', 'ASC')->paginate(10);

    return response()->json([
      'success' => true,
      'data' => $query
    ], 200);
  }

  public function create(Request $request) {
    $query = Brand::create([
      'name' => $request->input('name')
    ]);

    return response()->json([
      'success' => true,
      'data' => "Brand ".$query->name." has been created"
    ]);
  }

  public function update(Request $request, $id) {
    $brand = Brand::where('id', $id)->first();

    if(!$brand) {
      return response()->json([
        'success' => false,
        'data' => 'Data not found'
      ], 404);
    }

    $brand->update([
      'name' => $request->input('name')
    ]);

    return response()->json([
      'success' => true,
      'data' => "Brand ID {$id} information has been updated"
    ], 201);
  }

  public function delete(Request $request, $id) {
      $brand = Brand::find($id);

      if(!$brand) {
        return response()->json([
          'success' => false,
          'data' => "Data not found!"
        ], 404);
      }

      $brand->delete();
          
      return response()->json([
        'success' => true,
        'data' => "Brand ID {$id} has been deleted"
      ], 201);
  }

  public function search(Request $request) {
      $keyword = $request->input('keyword');

      $query = DB::table('brands')
                 ->select('id', 'name')
                 ->where('name', 'LIKE', '%'.$keyword.'%')
                 ->orderBy('name', 'ASC')
                 ->paginate(10)
      ;

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

  public function getbrand($id) {
      $brand = Brand::where('id', $id)->first();

      return response()->json([
        'success' => true,
        'data' => $brand
      ], 200);
  }
}