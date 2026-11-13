@extends('layouts.auth')
@section('title', 'Create account')

@section('content')
    <h1>Create your account</h1>
    <p class="muted">It takes a few seconds.</p>

    <form method="POST" action="{{ url('/register') }}" class="form" novalidate>
        @csrf
        <label>
            <span>Display name</span>
            <input type="text" name="name" value="{{ old('name') }}" autocomplete="name" maxlength="60" autofocus required>
        </label>
        @error('name') <p class="field-error">{{ $message }}</p> @enderror

        <label>
            <span>Username</span>
            <input type="text" name="username" value="{{ old('username') }}" autocomplete="username" maxlength="30" required>
        </label>
        @error('username') <p class="field-error">{{ $message }}</p> @enderror

        <label>
            <span>Email</span>
            <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required>
        </label>
        @error('email') <p class="field-error">{{ $message }}</p> @enderror

        <label>
            <span>Password <small class="muted">(8+ characters, letters and numbers)</small></span>
            <input type="password" name="password" autocomplete="new-password" required>
        </label>
        @error('password') <p class="field-error">{{ $message }}</p> @enderror

        <label>
            <span>Confirm password</span>
            <input type="password" name="password_confirmation" autocomplete="new-password" required>
        </label>

        <button type="submit" class="btn primary block">Create account</button>
    </form>

    <p class="muted center">Already registered? <a href="{{ route('login') }}">Sign in</a></p>
@endsection
