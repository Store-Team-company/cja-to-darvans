@extends('saas.layout')
@section('title', 'Team')
@section('content')
<div class="page-title"><span class="eyebrow">Access control</span><h1>Workspace Team</h1><p>Members can see only workspaces they belong to. Owners manage billing; importers cannot view credentials or subscription controls.</p></div>
<div class="card">
    <div class="split"><h2 style="margin:0">Members</h2><span class="tag">{{ $members->count() }} seats used</span></div>
    <div class="table-wrap mt"><table class="data-table"><thead><tr><th>Member</th><th>Email</th><th>Role</th><th>Status</th></tr></thead><tbody>
    @foreach($members as $member)
    <tr><td><strong>{{ $member->name }}</strong></td><td>{{ $member->email }}</td><td>{{ ucfirst($member->pivot->role) }}</td><td><span class="tag">{{ ucfirst($member->pivot->status) }}</span></td></tr>
    @endforeach
    </tbody></table></div>
    <p class="micro mt">Invitations and member-role editing will be enabled after invitation acceptance, revocation and seat-limit tests. Team access is read-only in this milestone.</p>
</div>
@endsection
