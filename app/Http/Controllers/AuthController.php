<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        \Log::info('LOGIN ATTEMPT', [
            'email' => $request->email,
            'db' => config('database.connections.mysql.database'),
            'host' => config('database.connections.mysql.host'),
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid email or password'
            ], 422);
        }

        // Optional: hapus token lama biar tidak numpuk
        $user->tokens()->delete();

        // Token khusus desktop / tauri
        $token = $user->createToken('desktop')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user
        ]);
    }

    public function logout(Request $request)
    {
        if ($request->user()) {
            $request->user()->currentAccessToken()?->delete();
        }

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }
}
