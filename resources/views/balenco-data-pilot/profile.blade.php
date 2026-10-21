@extends('balenco-data-pilot.layout')

@section('title', 'Admin Profile | Data Pilot')

@section('content')
<style>
    .bdp-profile-shell{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:18px;align-items:start}
    .bdp-profile-card{background:#fff;border:1px solid var(--bdp-border);border-radius:var(--bdp-radius);box-shadow:var(--bdp-shadow);padding:22px}
    .bdp-profile-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
    .bdp-profile-note{background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;border-radius:14px;padding:14px;margin-bottom:16px;font-size:13px;line-height:1.55}
    .bdp-profile-avatar{width:82px;height:82px;border-radius:24px;background:linear-gradient(135deg,#0f172a,#2563eb);color:#fff;display:grid;place-items:center;font-size:28px;font-weight:900;margin-bottom:14px}
    .bdp-profile-side h3{margin:0 0 6px;font-size:18px}.bdp-profile-side p{margin:0;color:#64748b}
    .bdp-profile-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px;flex-wrap:wrap}
    @media(max-width:980px){.bdp-profile-shell{grid-template-columns:1fr}.bdp-profile-grid{grid-template-columns:1fr}}
</style>

<div class="bdp-page-head">
    <div class="bdp-page-title">
        <h1>Admin Profile</h1>
        <p>Update the administrator name, email, username, and login password for Data Pilot.</p>
    </div>
</div>

<div class="bdp-profile-shell">
    <form class="bdp-profile-card" method="POST" action="{{ route('bdp.profile.update') }}">
        @csrf

        @if($errors->any())
            <div class="msg error">
                <strong>Please fix these fields:</strong>
                <ul style="margin:8px 0 0;padding-left:18px">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="bdp-profile-note">
            <strong>Security note:</strong> Use a strong password. Leave password fields empty if you only want to update name, email, or username.
        </div>

        <div class="bdp-card-header">
            <div>
                <h3 class="bdp-card-title">Profile Details</h3>
                <p class="bdp-card-subtitle">These details are shown in the admin header and used for login where supported.</p>
            </div>
        </div>

        <div class="bdp-profile-grid">
            <div>
                <label>Administrator name</label>
                <input type="text" name="name" value="{{ old('name', $profile->name ?? 'Admin') }}" required>
            </div>
            <div>
                <label>Email</label>
                <input type="email" name="email" value="{{ old('email', $profile->email ?? '') }}" placeholder="admin@example.com">
            </div>
            <div>
                <label>Username</label>
                <input type="text" name="username" value="{{ old('username', $profile->username ?? 'admin') }}" required>
            </div>
        </div>

        <div style="height:18px"></div>

        <div class="bdp-card-header">
            <div>
                <h3 class="bdp-card-title">Change Password</h3>
                <p class="bdp-card-subtitle">Leave blank to keep the current password.</p>
            </div>
        </div>

        <div class="bdp-profile-grid">
            <div>
                <label>New password</label>
                <input type="password" name="password" autocomplete="new-password" placeholder="Minimum 8 characters">
            </div>
            <div>
                <label>Confirm new password</label>
                <input type="password" name="password_confirmation" autocomplete="new-password" placeholder="Repeat new password">
            </div>
        </div>

        <div class="bdp-profile-actions">
            <a class="bdp-btn light" href="{{ route('bdp.products') }}">Cancel</a>
            <button class="bdp-btn orange" type="submit">Save Profile</button>
        </div>
    </form>

    <aside class="bdp-profile-card bdp-profile-side">
        @php
            $initials = collect(explode(' ', trim($profile->name ?? 'Admin')))->filter()->map(fn($p) => mb_substr($p, 0, 1))->take(2)->implode('');
            $initials = $initials ?: 'A';
        @endphp
        <div class="bdp-profile-avatar">{{ strtoupper($initials) }}</div>
        <h3>{{ $profile->name ?? 'Admin' }}</h3>
        <p>{{ $profile->email ?: 'No email saved' }}</p>
        <div style="height:14px"></div>
        <div class="bdp-info-list">
            <div class="bdp-info-row"><span>Username</span><strong>{{ $profile->username ?? 'admin' }}</strong></div>
            <div class="bdp-info-row"><span>Profile ID</span><strong>#{{ $profile->id ?? 1 }}</strong></div>
            <div class="bdp-info-row"><span>Updated</span><strong>{{ optional($profile->updated_at ?? null)->format('Y-m-d H:i') ?: '—' }}</strong></div>
        </div>
    </aside>
</div>
@endsection
