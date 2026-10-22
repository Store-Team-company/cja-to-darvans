<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verify your email | Data Pilot</title><style>body{font:16px/1.6 system-ui;color:#152138;max-width:580px;padding:40px 20px;margin:auto}button{padding:12px 19px;background:#12203c;border:0;border-radius:9px;color:#fff;font-weight:750}</style></head><body>
<h1>Verify your email address</h1>
<p>We sent a verification link to your email. Please confirm your address before accessing your workspace.</p>
@if(session('status') === 'verification-link-sent')<p role="status">Another verification link has been sent.</p>@endif
<form method="post" action="{{ route('saas.verification.send') }}">@csrf<button type="submit">Resend verification email</button></form>
<form method="post" action="{{ route('saas.logout') }}" style="margin-top:18px">@csrf<button type="submit">Sign out</button></form>
</body></html>
