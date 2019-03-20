<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Builder;
use App\User;

class AuthController extends Controller
{
    public function login(Request $request) {
        $mail = $request->input('email');

        $query = User::where('email', $mail)->first();
        if($query && ($query->count() > 0)) {
            if(Hash::check($request->input('password'), $query->password)) {
                $apiToken = base64_encode(str_random(40));

                $query->update(['remember_token' => $apiToken]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Log in successfully'
                ], 200);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Mismatched password'
                ], 400);
            }
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Email does not exist'
            ], 400);
        }
    }

    public function register(Request $request) {
        $makeSecure = app()->make('hash');

        $securedPass = $makeSecure->make($request->input('password'));

        $query = User::create([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'password' => $securedPass
        ]);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'Data has been created'
        ]);
    }

    public function getapi($id) {
        $user = User::find($id);

        return $user->remember_token;
    }
}