@extends('saas.layout')
@section('title', 'Verify email')
@section('content')
<div class="auth-wrap"><div class="card">
    <span class="eyebrow">One quick step</span><h1 style="margin:10px 0">Verify your email</h1><p>We sent a verification link to {{ auth()->user()->email }}. Confirm your address before accessing your workspace.</p>
    @if(session('status') === 'verification-link-sent')<div class="alert ok" role="status">We sent another verification link.</div>@endif
    <form method="post" action="{{ route('saas.verification.send') }}">@csrf<button type="submit" class="btn btn-primary auth-submit">Resend verification email</button></form>
    <form method="post" action="{{ route('saas.logout') }}">@csrf<button class="btn auth-submit" type="submit">Sign out</button></form>
</div></div>
@endsection
