@extends('saas.layout')
@section('title', 'Create account')
@section('content')
<div class="auth-wrap"><div class="card">
    <span class="eyebrow">Your new workspace</span><h1 style="margin:10px 0">Create your account</h1><p>Register your business to prepare its own CJ and Botble connections. Stripe plans are activated separately after email verification.</p>
    <form method="post" action="{{ route('saas.register.submit') }}">@csrf
        <div class="form-field"><label for="name">Your full name</label><input id="name" name="name" autocomplete="name" maxlength="100" required value="{{ old('name') }}">@error('name')<span class="error">{{ $message }}</span>@enderror</div>
        <div class="form-field"><label for="workspace_name">Business / store name</label><input id="workspace_name" name="workspace_name" maxlength="150" required value="{{ old('workspace_name') }}">@error('workspace_name')<span class="error">{{ $message }}</span>@enderror</div>
        <div class="form-field"><label for="email">Email address</label><input id="email" type="email" name="email" autocomplete="email" required value="{{ old('email') }}">@error('email')<span class="error">{{ $message }}</span>@enderror</div>
        <div class="form-field"><label for="password">Password (12+ characters)</label><input id="password" type="password" name="password" minlength="12" autocomplete="new-password" required><small>Use upper and lowercase letters and numbers.</small>@error('password')<span class="error">{{ $message }}</span>@enderror</div>
        <div class="form-field"><label for="password_confirmation">Confirm password</label><input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></div>
        <button class="btn btn-primary auth-submit" type="submit">Create workspace →</button>
    </form>
    <p class="fine-print">Already have an account? <a href="{{ route('saas.login') }}" style="color:#315acc;text-decoration:underline">Sign in</a></p>
</div></div>
@endsection
