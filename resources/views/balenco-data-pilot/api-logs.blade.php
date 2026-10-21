@extends('balenco-data-pilot.layout')

@section('content')
<div class="card">
    <h2>CJA / WooCommerce API Logs</h2>
    <p>This page shows every request CJA sends to Data Pilot when it thinks it is connected to WooCommerce.</p>
    <div style="background:#fff7ed;border:1px solid #fdba74;padding:12px;border-radius:8px;margin-bottom:15px">
        <strong>CJA Store URL:</strong> {{ url('/') }}<br>
        <strong>Client Key:</strong> <code>{{ config('balenco-data-pilot.cja_consumer_key') }}</code><br>
        <strong>Client Secret:</strong> <code>{{ config('balenco-data-pilot.cja_consumer_secret') }}</code><br>
        <small>Use these values in CJA WooCommerce OAuth Plugin connection.</small>
    </div>

    <form method="GET" action="{{ route('bdp.api.logs') }}" class="grid" style="grid-template-columns:1fr 160px 120px;align-items:end;margin-bottom:15px">
        <div>
            <label>Endpoint contains</label>
            <input type="text" name="endpoint" value="{{ request('endpoint') }}" placeholder="products, variations, batch...">
        </div>
        <div>
            <label>Status</label>
            <input type="text" name="status" value="{{ request('status') }}" placeholder="200">
        </div>
        <div><button class="btn" type="submit">Filter</button></div>
    </form>

    <form method="POST" action="{{ route('bdp.api.logs.clear') }}" onsubmit="return confirm('Clear all API logs?')" style="margin-bottom:15px">
        @csrf
        <button class="btn light" type="submit">Clear Logs</button>
    </form>
</div>

<div class="card">
    <table>
        <thead>
        <tr>
            <th>ID</th>
            <th>Time</th>
            <th>Method</th>
            <th>Endpoint</th>
            <th>Status</th>
            <th>Auth</th>
            <th>Note</th>
            <th>Payload</th>
        </tr>
        </thead>
        <tbody>
        @forelse($logs as $log)
            <tr>
                <td>{{ $log->id }}</td>
                <td>{{ optional($log->created_at)->format('Y-m-d H:i:s') }}</td>
                <td>{{ $log->method }}</td>
                <td><code>{{ $log->path }}</code></td>
                <td>{{ $log->status_code }}</td>
                <td>{{ $log->authorized ? 'Yes' : 'No' }}</td>
                <td>{{ $log->note }}</td>
                <td>
                    <details>
                        <summary>View</summary>
                        <strong>Request</strong>
                        <pre style="white-space:pre-wrap;max-width:520px">{{ json_encode($log->request_body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        <strong>Response</strong>
                        <pre style="white-space:pre-wrap;max-width:520px">{{ json_encode($log->response_body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                </td>
            </tr>
        @empty
            <tr><td colspan="8">No API logs yet. Connect CJA to Data Pilot and try a connection test or product listing.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div style="margin-top:15px">{{ $logs->links() }}</div>
</div>
@endsection
