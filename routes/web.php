<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

$router->get('/', function () use ($router) {
    return $router->app->version();
});

$router->post('/register', 'AuthController@register');
$router->post('/login', 'AuthController@login');
$router->get('/getapi/{id}', 'AuthController@getapi');

$router->post('/product/all', ['middleware' => 'auth', 'uses' => 'ProductController@index']);
$router->post('/product/brand/all', ['middleware' => 'auth', 'uses' => 'ProductController@loadBrand']);
$router->post('/product/type/all', ['middleware' => 'auth', 'uses' => 'ProductController@loadType']);
$router->post('/product/create', ['middleware' => 'auth', 'uses' => 'ProductController@create']);
$router->post('/product/update/{id}', ['middleware' => 'auth', 'uses' => 'ProductController@update']);
$router->post('/product/delete/{id}', ['middleware' => 'auth', 'uses' => 'ProductController@delete']);
$router->post('/product/search/', ['middleware' => 'auth', 'uses' => 'ProductController@search']);
$router->get('/product/get/{id}', ['middleware' => 'auth', 'uses' => 'ProductController@getproduct']);

$router->post('/brand/all', ['middleware' => 'auth', 'uses' => 'BrandController@index']);
$router->post('/brand/create', ['middleware' => 'auth', 'uses' => 'BrandController@create']);
$router->post('/brand/update/{id}', ['middleware' => 'auth', 'uses' => 'BrandController@update']);
$router->post('/brand/delete/{id}', ['middleware' => 'auth', 'uses' => 'BrandController@delete']);
$router->post('/brand/search/', ['middleware' => 'auth', 'uses' => 'BrandController@search']);
$router->get('/brand/get/{id}', ['middleware' => 'auth', 'uses' => 'BrandController@getbrand']);

$router->post('/type/all', ['middleware' => 'auth', 'uses' => 'TypeController@index']);
$router->post('/type/create', ['middleware' => 'auth', 'uses' => 'TypeController@create']);
$router->post('/type/update/{id}', ['middleware' => 'auth', 'uses' => 'TypeController@update']);
$router->post('/type/delete/{id}', ['middleware' => 'auth', 'uses' => 'TypeController@delete']);
$router->post('/type/search', ['middleware' => 'auth', 'uses' => 'TypeController@search']);
$router->get('/type/get/{id}', ['middleware' => 'auth', 'uses' => 'TypeController@gettype']);

$router->post('/invoice/all', ['middleware' => 'auth', 'uses' => 'InvoiceController@index']);
$router->post('/invoice/user/all', ['middleware' => 'auth', 'uses' => 'InvoiceController@loadUser']);
$router->post('/invoice/product/all', ['middleware' => 'auth', 'uses' => 'InvoiceController@loadProduct']);
$router->post('/invoice/create', ['middleware' => 'auth', 'uses' => 'InvoiceController@create']);
$router->post('/invoice/update/{id}', ['middleware' => 'auth', 'uses' => 'InvoiceController@update']);
$router->post('/invoice/delete/{id}', ['middleware' => 'auth', 'uses' => 'InvoiceController@delete']);
$router->post('/invoice/search', ['middleware' => 'auth', 'uses' => 'InvoiceController@search']);
$router->get('/invoice/get/{id}', ['middleware' => 'auth', 'uses' => 'InvoiceController@getinvoice']);

$router->post('/shop/all', 'ShopController@index');
$router->post('/shop/get/{id}', 'ShopController@show');
$router->post('/shop/buy', ['middleware' => 'auth', 'uses' => 'ShopController@buy']);
$router->post('/shop/cart', ['middleware' => 'auth', 'uses' => 'ShopController@cart']);
$router->post('/shop/history', ['middleware' => 'auth', 'uses' => 'ShopController@historyTransaction']);
$router->post('/shop/pay', ['middleware' => 'auth', 'uses' => 'ShopController@payment']);
$router->post('/shop/filter', ['middleware' => 'auth', 'uses' => 'ShopController@filter']);

$router->post('/user/get', ['middleware' => 'auth', 'uses' => 'UserController@mycart']);
$router->post('/user/search', ['middleware' => 'auth', 'uses' => 'UserController@search']);