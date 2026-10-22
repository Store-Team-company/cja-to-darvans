@extends('saas.layout')
@section('title', 'Enter email code')
@section('content')
<div class="auth-wrap"><div class="card">
    <span class="eyebrow">Passwordless sign in</span>
    <h1 style="margin:10px 0">Check your inbox</h1>
    <p>Enter the six-digit code sent to <strong>{{ $email ?: 'your email address' }}</strong>. It expires in 10 minutes.</p>
    @if(session('status'))<div class="alert ok" role="status">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="alert" role="alert">{{ session('error') }}</div>@endif
    <form method="post" action="{{ route('saas.otp.verify') }}">@csrf
        <div class="form-field">
            <label for="code">Your six-digit verification code</label>
            <input type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                id="code" name="code" autocomplete="one-time-code" required
                style="font-size:24px;font-weight:750;letter-spacing:7px;text-align:center"
                aria-describedby="code-help">
            <small id="code-help">The code works only once. Up to five incorrect attempts are allowed.</small>
            @error('code')<span class="error" role="alert">{{ $message }}</span>@enderror
        </div>
        <button type="submit" class="btn btn-primary auth-submit">Verify and continue →</button>
    </form>
    <div style="margin-top:25px;padding-top:18px;border-top:1px solid #e5eaf2">
        <p style="font-weight:750;color:#172038;margin-bottom:8px">Didn't get an email?</p>
        <form method="post" action="{{ route('saas.otp.send') }}">@csrf
            <div class="form-field"><label for="resend-email">Your email</label>
                <input type="email" id="resend-email" name="email" value="{{ $email }}" required></div>
            <button type="submit" class="btn" style="width:100%">Request a new code</button>
        </form>
        <p class="micro" style="margin:12px 0 0">You may request a new code after 60 seconds. Check spam or confirm your mail server is configured.</p>
        <p class="fine-print"><a href="{{ route('saas.login') }}" style="text-decoration:underline">← Back to sign in</a></p>
    </div>
</div></div>
@endsection
