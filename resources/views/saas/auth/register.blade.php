@extends('saas.layout')
@section('title', 'Create account')
@section('content')
<div class="auth-wrap"><div class="card">
    <span class="eyebrow">New customer</span>
    <h1 style="margin:10px 0">Create your account</h1>
    <p>Choose a strong password. A platform administrator must approve your account before your first login; no verification email is required for administrator approval.</p>
    @if($errors->any())<div class="alert" role="alert">{{ $errors->first() }}</div>@endif
    <form method="post" action="{{ route('saas.register.submit') }}">@csrf
        <div class="form-field"><label for="name">Full name</label><input id="name" name="name" maxlength="100" autocomplete="name" value="{{ old('name') }}" required></div>
        <div class="form-field"><label for="workspace_name">Business or store</label><input id="workspace_name" name="workspace_name" maxlength="150" value="{{ old('workspace_name') }}" required></div>
        <div class="form-field"><label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" required></div>
        <div class="form-field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required>
        <small>At least 12 characters, with uppercase, lowercase and numbers.</small></div>
        <div class="form-field"><label for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required></div>
        <button class="btn btn-primary auth-submit" type="submit">Request account approval</button>
    </form>
    <p class="fine-print">Already registered? <a href="{{ route('saas.login') }}" style="color:#315acc;text-decoration:underline">Sign in</a></p>
</div></div>
@endsection
