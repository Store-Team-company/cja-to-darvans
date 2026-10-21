<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Balenco Data Pilot</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f6f7fb;color:#111827;margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh}.box{width:100%;max-width:420px;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:26px;box-shadow:0 10px 25px rgba(0,0,0,.06)}h1{margin:0 0 8px;font-size:24px}.muted{color:#6b7280;margin:0 0 20px}label{display:block;font-weight:700;margin:14px 0 6px}input{width:100%;padding:12px;border:1px solid #d1d5db;border-radius:10px;box-sizing:border-box}.btn{margin-top:18px;width:100%;background:#FF6B1A;color:#fff;padding:12px 14px;border-radius:10px;border:0;font-weight:700;cursor:pointer}.msg{padding:12px;border-radius:8px;margin-bottom:12px}.success{background:#ecfdf5;color:#065f46}.error{background:#fef2f2;color:#991b1b}.brand{color:#1E2A44;font-weight:800}
    </style>
</head>
<body>
<div class="box">
    <h1><span class="brand">Balenco</span> Data Pilot</h1>
    <p class="muted">Login to manage product data.</p>
    @if(session('success'))<div class="msg success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="msg error">{{ session('error') }}</div>@endif
    <form method="POST" action="{{ route('bdp.login') }}">
        @csrf
        <label>Username</label>
        <input type="text" name="username" value="{{ old('username') }}" required autofocus>
        <label>Password</label>
        <input type="password" name="password" required>
        <button class="btn" type="submit">Login</button>
    </form>
</div>
</body>
</html>
