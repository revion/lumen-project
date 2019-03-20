<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Invoice;

class InvoiceController extends Controller {
    public function index() {
      $query = DB::table('invoices')
                  ->leftJoin('users', 'users.id', '=', 'invoices.id_user')
                  ->leftJoin('products', 'products.id', '=', 'invoices.id_product')
                  ->leftJoin('brands', 'brands.id', '=', 'products.id_brand')
                  ->select('invoices.id', DB::raw('users.email AS user_email'), DB::raw('CONCAT(brands.name, " ", products.name) AS product_name'), 'amount', 'has_bought')
                  ->orderBy('brands.name', 'ASC')
                  ->orderBy('products.name', 'ASC')
                  ->paginate(10)
      ;

      return response()->json([
        'success' => true,
        'data' => $query
      ], 200);
    }

    public function loadUser() {
      $query = DB::select('SELECT id, CONCAT(name, " - " , email) AS name from users ORDER BY name ASC');

      return response()->json([
        'success' => true,
        'data' => $query
      ], 200);
    }

    public function loadProduct() {
      $query = DB::select('SELECT products.id, CONCAT(brands.name, " ", products.name) AS name from products LEFT JOIN brands ON brands.id = products.id_brand ORDER BY brands.name ASC, products.name ASC');

      return response()->json([
        'success' => true,
        'data' => $query
      ], 200);
    }

  public function create(Request $request) {
    $query = DB::insert('insert into invoices(id_user, id_product, amount) values(:id_user, :id_product, :amount)', [
      'id_user' => $request->input('id_user'),
      'id_product' => $request->input('id_product'),
      'amount' => $request->input('amount'),
    ]);

    return response()->json([
      'success' => true,
      'data' => "Invoice has been created"
    ], 200);
  }

    public function update(Request $request, $id) {
      $invoice = Invoice::where('id', $id)->first();

      if(!$invoice) {
        return response()->json([
          'success' => false,
          'data' => 'Data not found'
        ], 404);
      }
      
      $invoice->update([
        'id_user' => $request->input('id_user'),
        'id_product' => $request->input('id_product'),
        'amount' => $request->input('amount')
      ]);

      return response()->json([
        'success' => true,
        'data' => "Invoice ID {$id} information has been updated"
      ], 201);
    }

    public function paid(Request $request, $id) {
      $paid = Invoice::where('id', $id)->first();

      if(!$paid) {
        return response()->json([
          'success' => false,
          'data' => 'Data not found'
        ], 404);
      }

      $paid->update([
        $request->input('has_bought')
      ]);

      return response()->json([
        'success' => true,
        'data' => "Invoice ID {$id} has been paid"
      ]);
    }

    public function delete($id) {
      $invoice = Invoice::where('id', $id)->first();

      if(!$invoice) {
        return response()->json([
          'success' => false,
          'data' => 'Data not found'
        ]);
      }

      $invoice->delete();

      return response()->json([
        'success' => true,
        'data' => "Invoice ID {$id} has been deleted"
      ], 201);
    }

  public function search(Request $request) {
    $keyword = $request->keyword;

    // $invoices = DB::select('SELECT invoice.id, invoice.id_user, invoice.id_product, users.email AS user_email, products.name AS product_name, amount FROM invoice LEFT JOIN users ON users.id = invoice.id_user LEFT JOIN products ON products.id = invoice.id_product WHERE users.email LIKE "%:keyword%" OR products.name LIKE "%:keyword%"',
    // [
    //   'keyword' => $keyword,
    // ]);
    $invoices = DB::table('invoices')
                  ->leftJoin('users', 'users.id', '=', 'invoices.id_user')
                  ->leftJoin('products', 'products.id', '=', 'invoices.id_product')
                  ->leftJoin('brands', 'brands.id', '=', 'products.id_brand')
                  ->select('invoices.id', 'invoices.id_user', 'invoices.id_product', DB::raw('users.email AS user_email'), DB::raw('CONCAT(brands.name, " ", products.name) AS product_name'), 'amount', 'has_bought')
                  ->where(function ($query) use($request){
                    $query->where('products.name', 'LIKE', '%'.$request->keyword.'%')
                    ->orWhere('brands.name', 'LIKE', '%'.$request->keyword.'%');
                  })
                  ->paginate(10);

    if($invoices->count() < 1) {
      return response()->json([
        'success' => false,
        'data' => 'No data with keyword: '.$keyword
      ]);
    }

    return response()->json([
      'success' => true,
      'data' => $invoices
    ]);
  }

  public function getinvoice($id) {
    $invoice = DB::select('SELECT 
      invoices.id, 
      invoices.id_user, 
      invoices.id_product, 
      users.email AS user_email, 
      products.name AS product_name, 
      amount 
      FROM invoices 
      LEFT JOIN users ON 
      users.id = invoices.id_user 
      LEFT JOIN products ON 
      products.id = invoices.id_product 
      WHERE invoices.id = :id',
    [
      'id' => $id
    ]);

    if(!$invoice) {
      return response()->json([
        'success' => false,
        'data' => "Data not found"
      ], 404);
    }

    return response()->json([
      'success' => true,
      'data' => $invoice
    ], 200);
  }
}