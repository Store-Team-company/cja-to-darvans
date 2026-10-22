@extends('saas.layout')
@section('title', 'Sign in')
@section('content')
<div class="auth-wrap"><div class="card">
    <span class="eyebrow">Secure customer access</span>
    <h1 style="margin:10px 0">Sign in to Data Pilot</h1>
    <p>Use your email and password. New customers require administrator approval.</p>
    @if(session('status'))<div class="alert ok" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert" role="alert">{{ $errors->first() }}</div>@endif
    <form method="post" action="{{ route('saas.login.submit') }}">@csrf
        <div class="form-field">
            <label for="email">Email address</label>
            <input id="email" type="email" name="email" autocomplete="username" required value="{{ old('email') }}">
        </div>
        <div class="form-field">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" autocomplete="current-password" required>
        </div>
        <button class="btn btn-primary auth-submit" type="submit">Sign in with password</button>
    </form>
    <p class="micro" style="margin-top:16px"><a href="{{ route('saas.password.request') }}">Forgot password?</a></p>
    <p class="micro" style="margin-top:12px">Don't know your password? Ask the Data Pilot administrator to issue a temporary password. You'll change it after signing in.</p>
    <p class="fine-print">
        @if(config('saas.registration_enabled'))
            New to Data Pilot? <a href="{{ route('saas.register') }}" style="color:#315acc;text-decoration:underline">Create account</a>
        @else
            New customer registration is currently closed.
        @endif
    </p>
</div></div>
@endsection
