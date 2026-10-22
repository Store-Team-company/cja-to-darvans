@extends('saas.layout')
@section('title', 'Set new password')
@section('content')
<div class="auth-wrap"><div class="card">
    <span class="eyebrow">Account security</span>
    <h1 style="margin:10px 0">Change your temporary password</h1>
    <p>Before entering your workspace, replace the temporary password provided by your Data Pilot administrator.</p>
    @if($errors->any())<div class="alert" role="alert">{{ $errors->first() }}</div>@endif
    <form method="post" action="{{ route('saas.password.change') }}">@csrf
        <div class="form-field"><label for="current_password">Temporary password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
        <div class="form-field"><label for="password">New password</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required><small>Use 12+ characters with uppercase, lowercase and numbers.</small></div>
        <div class="form-field"><label for="password_confirmation">Confirm new password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required></div>
        <button class="btn btn-primary auth-submit" type="submit">Save password and continue</button>
    </form>
    <form method="post" action="{{ route('saas.logout') }}" style="margin-top:12px">@csrf
        <button class="btn auth-submit" type="submit">Sign out</button>
    </form>
</div></div>
@endsection
