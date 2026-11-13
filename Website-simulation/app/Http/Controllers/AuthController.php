<?php

namespace App\Http\Controllers;

use App\Models\ChatToken;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $request->merge(['username' => Str::lower((string) $request->input('username'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'username' => ['required', 'string', 'regex:/^[a-z0-9_.]{3,30}$/', 'unique:users,username'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'username.regex' => 'Username must be 3-30 characters: letters, numbers, "." or "_".',
        ]);

        $user = User::create($data); // password is hashed by the model cast

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('chat');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $isEmail = filter_var($data['login'], FILTER_VALIDATE_EMAIL) !== false;
        $field = $isEmail ? 'email' : 'username';
        $value = $isEmail ? $data['login'] : Str::lower($data['login']);

        // Brute-force protection: 5 failures per login+IP per minute.
        $throttleKey = Str::lower($data['login']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'login' => 'Too many attempts. Try again in '.RateLimiter::availableIn($throttleKey).' seconds.',
            ]);
        }

        if (! Auth::attempt([$field => $value, 'password' => $data['password']], $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 60);
            throw ValidationException::withMessages(['login' => 'These credentials do not match our records.']);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        return redirect()->intended(route('chat'));
    }

    public function logout(Request $request): RedirectResponse
    {
        if ($user = $request->user()) {
            ChatToken::where('user_id', $user->id)->delete(); // kill pending socket credentials
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
