@extends('saas.layout')
@section('title', 'Sign in')
@section('content')
<div class="auth-wrap"><div class="card">
    <span class="eyebrow">Welcome back</span>
    <h1 style="margin:10px 0">Sign in to Data Pilot</h1>
    <p>Choose your preferred way to access your workspace.</p>
    @if(session('status'))<div class="alert ok" role="status">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="alert" role="alert">{{ session('error') }}</div>@endif
    <section aria-labelledby="otp-title">
        <h2 id="otp-title" style="font-size:20px;margin-bottom:5px">Email one-time code</h2>
        <p style="font-size:13px;margin-bottom:18px">No password needed. We’ll email you a six-digit code valid for 10 minutes.</p>
        <form method="post" action="{{ route('saas.otp.send') }}">@csrf
            <div class="form-field">
                <label for="otp-email">Email address</label>
                <input type="email" id="otp-email" name="email" autocomplete="username" value="{{ old('email', session('saas_otp_email')) }}" required>
                @error('email')<span class="error" role="alert">{{ $message }}</span>@enderror
            </div>
            <button type="submit" class="btn btn-primary auth-submit">Continue with email OTP →</button>
        </form>
    </section>
    <div style="display:flex;align-items:center;gap:12px;margin:25px 0">
        <div style="height:1px;background:#e1e7f0;flex:1"></div><span class="micro">OR</span><div style="height:1px;background:#e1e7f0;flex:1"></div>
    </div>
    <details @if($errors->has('password')) open @endif>
        <summary style="cursor:pointer;padding:9px 0;font-weight:800;color:#2d53bd">Sign in using a password</summary>
        <form method="post" action="{{ route('saas.login.submit') }}" style="margin-top:16px">@csrf
            <div class="form-field">
                <label for="password-email">Email address</label>
                <input type="email" id="password-email" name="email" autocomplete="username" value="{{ old('email') }}" required>
            </div>
            <div class="form-field">
                <div class="split"><label for="password">Password</label>
                    <a href="{{ route('saas.password.request') }}" class="micro" style="text-decoration:underline;color:#315acc">Forgot password?</a></div>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
                @error('password')<span class="error" role="alert">{{ $message }}</span>@enderror
            </div>
            <button type="submit" class="btn auth-submit">Sign in with password</button>
        </form>
    </details>
    <p class="fine-print">New to Data Pilot?
        @if(config('saas.registration_enabled'))<a href="{{ route('saas.register') }}" style="text-decoration:underline;color:#315acc">Create an account</a>
        @else<span class="subtle">Registration opens after beta verification.</span>@endif
    </p>
</div></div>
@endsection
