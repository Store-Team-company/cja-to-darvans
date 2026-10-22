@extends('saas.layout')
@section('title', 'Reset password')
@section('content')
<div class="auth-wrap"><div class="card">
    <span class="eyebrow">Account recovery</span><h1 style="margin:10px 0">Forgot your password?</h1><p>Enter your registered email. If we find a matching account, we'll send password-reset instructions.</p>
    @if(session('status'))<div class="alert ok" role="status">{{ session('status') }}</div>@endif
    <form method="post" action="{{ route('saas.password.email') }}">@csrf
        <div class="form-field"><label for="email">Email address</label><input type="email" id="email" name="email" required value="{{ old('email') }}">@error('email')<span class="error">{{ $message }}</span>@enderror</div>
        <button class="btn btn-primary auth-submit" type="submit">Send reset instructions</button>
    </form>
    <p class="fine-print"><a href="{{ route('saas.login') }}" style="text-decoration:underline">← Back to sign in</a></p>
</div></div>
@endsection
