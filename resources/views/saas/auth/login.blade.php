@extends('saas.layout')
@section('title', 'Sign in')
@section('content')
<div class="auth-wrap"><div class="card">
    <span class="eyebrow">Welcome back</span><h1 style="margin:10px 0">Sign in to Data Pilot</h1><p>Access your private CJ → Botble workspace.</p>
    @if(session('status'))<div class="alert ok" role="status">{{ session('status') }}</div>@endif
    <form method="post" action="{{ route('saas.login.submit') }}">@csrf
        <div class="form-field"><label for="email">Email address</label><input type="email" id="email" name="email" autocomplete="username" value="{{ old('email') }}" required>@error('email')<span class="error" role="alert">{{ $message }}</span>@enderror</div>
        <div class="form-field"><div class="split"><label for="password">Password</label><a href="{{ route('saas.password.request') }}" class="micro" style="color:#315acc;text-decoration:underline">Forgot password?</a></div><input type="password" id="password" name="password" autocomplete="current-password" required></div>
        <button type="submit" class="btn btn-primary auth-submit">Sign in →</button>
    </form>
    <p class="fine-print">New to Data Pilot?
    @if(config('saas.registration_enabled'))<a href="{{ route('saas.register') }}" style="color:#315acc;text-decoration:underline">Create an account</a>
    @else<span class="subtle">Registration opens after beta verification.</span>@endif
    </p>
</div></div>
@endsection
