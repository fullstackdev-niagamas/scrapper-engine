<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $cred = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');
        
        if (Auth::attempt($cred, $remember)) {
            $request->session()->regenerate();
            $request->session()->put('login_at', time());
            return redirect()->intended(route('batches.index'));
        }

        // if (!Auth::attempt($cred, $remember)) {

        // return response()->json([
        // 'debug' => [
        //     'input_email' => $cred['email'],
        //     'input_password' => $cred['password'],
        //     'hashed_password_in_db' => optional(\App\Models\User::where('email', $cred['email'])->first())->password,
        // ]
        // ]);
        // }


        throw ValidationException::withMessages([
            'email' => 'Email atau kata sandi salah.',
        ]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
