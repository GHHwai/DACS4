@extends('layouts.auth')
@section('title', 'Sign in')

@section('content')
    <h1>Welcome back</h1>
    <p class="muted">Sign in to continue chatting.</p>

    <form method="POST" action="{{ url('/login') }}" class="form" novalidate>
        @csrf
        <label>
            <span>Username or email</span>
            <input type="text" name="login" value="{{ old('login') }}" autocomplete="username" autofocus required>
        </label>
        @error('login') <p class="field-error">{{ $message }}</p> @enderror

        <label>
            <span>Password</span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        @error('password') <p class="field-error">{{ $message }}</p> @enderror

        <label class="check">
            <input type="checkbox" name="remember" value="1"> <span>Keep me signed in</span>
        </label>

        <button type="submit" class="btn primary block">Sign in</button>
    </form>

    <p class="muted center">New here? <a href="{{ route('register') }}">Create an account</a></p>
@endsection
