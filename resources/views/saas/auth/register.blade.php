@extends('saas.layout')
@section('title', 'Create account')
@section('content')
<div class="auth-wrap"><div class="card">
    <span class="eyebrow">Your new workspace</span>
    <h1 style="margin:10px 0">Create your account</h1>
    <p>Start with your email. We’ll send a six-digit code to verify it. A password is optional.</p>
    @if(session('error'))<div class="alert" role="alert">{{ session('error') }}</div>@endif
    <form method="post" action="{{ route('saas.register.submit') }}">@csrf
        <div class="form-field">
            <label for="name">Your full name</label>
            <input id="name" name="name" autocomplete="name" maxlength="100" required value="{{ old('name') }}">
            @error('name')<span class="error" role="alert">{{ $message }}</span>@enderror
        </div>
        <div class="form-field">
            <label for="workspace_name">Business or store name</label>
            <input id="workspace_name" name="workspace_name" maxlength="150" required value="{{ old('workspace_name') }}">
            @error('workspace_name')<span class="error" role="alert">{{ $message }}</span>@enderror
        </div>
        <div class="form-field">
            <label for="email">Email address</label>
            <input id="email" type="email" name="email" autocomplete="email" required value="{{ old('email') }}">
            @error('email')<span class="error" role="alert">{{ $message }}</span>@enderror
        </div>
        <div class="card" style="padding:16px;box-shadow:none;background:#f8faff;margin:6px 0 20px">
            <strong style="display:block">✓ Continue without a password</strong>
            <p style="margin:5px 0 0;font-size:13px">We'll email you a one-time code. You can always add a password later using Forgot password.</p>
        </div>
        <details style="padding:0 0 17px" @if($errors->has('password') || $errors->has('password_confirmation')) open @endif>
            <summary style="cursor:pointer;color:#274ec0;font-weight:800;padding:8px 0">Prefer a password? Set one (optional)</summary>
            <div class="form-field" style="margin-top:12px">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="12">
                <small>At least 12 characters, including uppercase, lowercase and numbers.</small>
                @error('password')<span class="error" role="alert">{{ $message }}</span>@enderror
            </div>
            <div class="form-field">
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12">
                @error('password_confirmation')<span class="error" role="alert">{{ $message }}</span>@enderror
            </div>
        </details>
        <button class="btn btn-primary auth-submit" type="submit">Create workspace →</button>
    </form>
    <p class="fine-print">Already registered?
        <a href="{{ route('saas.login') }}" style="color:#315acc;text-decoration:underline">Sign in with email OTP</a>
    </p>
</div></div>
@endsection
