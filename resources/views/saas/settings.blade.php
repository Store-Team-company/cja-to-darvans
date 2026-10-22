@extends('saas.layout')
@section('title', 'Workspace Settings')
@section('content')
<div class="page-title"><span class="eyebrow">Account</span><h1>Workspace Settings</h1><p>Basic account preferences for your Data Pilot business workspace.</p></div>
<div class="card" style="max-width:650px">
<h2>Workspace name</h2><p>Displayed in your private dashboard and team navigation.</p>
<form method="post" action="{{ route('saas.workspace.settings.update', $workspace->public_id) }}">
@csrf @method('PATCH')
<div class="form-field"><label for="workspace_name">Business / workspace name</label><input id="workspace_name" name="name" value="{{ old('name', $workspace->name) }}" maxlength="150" required @if($role !== 'owner') disabled @endif>@error('name')<span class="error" role="alert">{{ $message }}</span>@enderror</div>
@if($role === 'owner')<button class="btn btn-primary" type="submit">Save workspace name</button>@else<p class="micro">Only workspace owners can edit the business name.</p>@endif
</form>
</div>
@endsection
