<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Create workspace | Data Pilot</title>
<style>body{background:#f5f7fb;font:15px/1.5 system-ui,sans-serif;color:#18243a;margin:0}.card{max-width:470px;margin:6vh auto;padding:30px;background:white;border:1px solid #e0e6ef;border-radius:16px}h1{margin:0 0 5px}p{color:#66758b;margin:0 0 18px}label{display:block;font-weight:650;margin:13px 0 5px}input{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:9px;padding:11px;font:inherit}button{margin-top:22px;width:100%;background:#101b36;color:white;border:0;border-radius:9px;padding:13px;font-weight:750}.error{color:#b91c1c;font-size:13px}a{color:#1d4ed8}</style></head>
<body><main class="card"><h1>Create your workspace</h1><p>Register to prepare your own CJ and Botble connections. Subscription checkout is not yet available in this development phase.</p>
<form method="post" action="{{ route('saas.register.submit') }}">@csrf
<label for="name">Your name</label><input id="name" name="name" value="{{ old('name') }}" required>
<label for="workspace_name">Business or store name</label><input id="workspace_name" name="workspace_name" value="{{ old('workspace_name') }}" required>
<label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" required>
<label for="password">Password (12+ characters)</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required>
<label for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
@foreach($errors->all() as $error)<div class="error" role="alert">{{ $error }}</div>@endforeach
<button type="submit">Create workspace</button></form>
<p style="margin-top:16px">Already registered? <a href="{{ route('saas.login') }}">Sign in</a></p>
</main></body></html>
