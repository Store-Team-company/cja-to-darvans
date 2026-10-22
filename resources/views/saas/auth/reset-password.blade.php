@extends('saas.layout')
@section('title', 'Choose new password')
@section('content')
<div class="auth-wrap"><div class="card">
    <span class="eyebrow">Account recovery</span><h1 style="margin:10px 0">Choose a new password</h1><p>Use a strong password you haven't used previously.</p>
    <form method="post" action="{{ route('saas.password.update') }}">@csrf<input type="hidden" name="token" value="{{ $token }}">
        <div class="form-field"><label for="email">Email address</label><input id="email" type="email" name="email" required value="{{ old('email', $email) }}">@error('email')<span class="error">{{ $message }}</span>@enderror</div>
        <div class="form-field"><label for="password">New password</label><input id="password" name="password" type="password" minlength="12" autocomplete="new-password" required>@error('password')<span class="error">{{ $message }}</span>@enderror</div>
        <div class="form-field"><label for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" minlength="12" autocomplete="new-password" required></div>
        <button class="btn btn-primary auth-submit" type="submit">Update password</button>
    </form>
</div></div>
@endsection
