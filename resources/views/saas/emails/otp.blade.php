<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Your Data Pilot code</title></head>
<body style="font-family:Arial,sans-serif;color:#172038;padding:28px">
    <main style="max-width:480px;margin:auto">
        <h1 style="font-size:24px">Data Pilot</h1>
        <p>Use this one-time code to {{ $purpose === 'register' ? 'verify your email and finish creating your workspace' : 'sign in to your workspace' }}:</p>
        <div style="letter-spacing:10px;font-size:32px;font-weight:bold;margin:22px 0">{{ $otp }}</div>
        <p>This code expires in 10 minutes and works only once. Never share it with anyone.</p>
        <p style="font-size:13px;color:#64748b">If you did not request it, ignore this message.</p>
    </main>
</body>
</html>
