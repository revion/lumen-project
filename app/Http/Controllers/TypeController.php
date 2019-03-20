<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Type;

class TypeController extends Controller {
  public function index() {
    $query = DB::table('types')->select('*')->orderBy('name', 'ASC')->paginate(10);

    return response()->json([
      'success' => true,
      'data' => $query
    ], 200);
  }

  public function create(Request $request) {
    $query = Type::create([
      'name' => $request->input('name')
    ]);

    return response()->json([
      'success' => true,
      'data' => 'Product Type '.$query->name.' has been created'
    ], 200);
  }

  public function update(Request $request, $id) {
    $type = Type::find($id);

    if(!$type) {
      return response()->json([
          'success' => false,
          'data' => 'Data not found'
      ], 404);
    }

    $type->update([
      'name' => $request->input('name')
    ]);

    return response()->json([
      'success' => true,
      'data' => "Type ID {$id} information has been updated"
    ], 201);
  }

  public function delete($id) {
    $type = Type::where('id', $id)->first();

    if(!$type) {
      return response()->json([
        'success' => false,
        'data' => "Data not found"
      ], 404);
    }

    $type->delete();

    return response()->json([
      'success' => true,
      'data' => "Type ID {$id} has been deleted"
    ], 201);
  }

  public function search(Request $request) {
    $keyword = $request->input('keyword');

    $query = DB::table('types')
               ->select('id', 'name')
               ->where('name', 'LIKE', '%'.$keyword.'%')
               ->orderBy('name', 'ASC')
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

  public function gettype($id) {
      $type = Type::where('id', $id)->first();

      return response()->json([
        'success' => true,
        'data' => $type
      ], 200);
  }
}