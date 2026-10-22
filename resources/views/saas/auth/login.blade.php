<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in | Data Pilot</title>
<style>body{background:#f5f7fb;font:15px/1.55 system-ui,sans-serif;color:#18243a;margin:0}.card{max-width:440px;margin:8vh auto;padding:35px;background:white;border:1px solid #e0e6ef;border-radius:16px}h1{margin:0 0 5px}p{color:#66758b;margin:0 0 20px}label{display:block;font-weight:650;margin:16px 0 6px}input{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:9px;padding:12px;font:inherit}button{margin-top:24px;width:100%;background:#101b36;color:white;border:0;border-radius:9px;padding:13px;font-weight:750}.error{color:#b91c1c}a{color:#1d4ed8}</style></head>
<body><main class="card"><h1>Welcome to Data Pilot</h1><p>Access your CJ-to-Botble workspace</p>
<form method="post" action="{{ route('saas.login.submit') }}">@csrf
<label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required>
<label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required>
@error('email')<p class="error" role="alert">{{ $message }}</p>@enderror
<button type="submit">Sign in</button></form>
@if(config('saas.registration_enabled'))<p style="margin-top:17px">New here? <a href="{{ route('saas.register') }}">Create a workspace</a></p>@endif
</main></body></html>
