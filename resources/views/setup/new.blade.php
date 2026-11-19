@extends('layouts.app')
@section('title', 'New Setup')
@section('content')
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 page-heading invoice-details-heading mb-1">New Setup</h1>
        <p class="text-muted mb-0">Create a separate software copy and blank database for another customer. LipSip data is never changed.</p>
    </div>
    <a href="{{ route('setup.index') }}" class="btn btn-outline-secondary btn-sm">Back to Setup</a>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger">
        <div class="fw-semibold mb-1">Could not create the new project</div>
        <ul class="mb-0">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if(!$configured)
    <div class="alert alert-warning">
        New Setup is not fully configured on this server yet.
        Software must set <code>NEW_SETUP_PLESK_USER</code> and <code>NEW_SETUP_PLESK_PASSWORD</code> in the live <code>.env</code>.
    </div>
@endif

@if(!empty($result))
    <div class="border rounded p-3 mb-4" style="background:#f0fdf4;">
        <h2 class="h6 mb-2">Created successfully</h2>
        <div class="row g-2 small">
            <div class="col-md-6"><strong>Project:</strong> {{ $result['project_name'] }}</div>
            <div class="col-md-6"><strong>URL:</strong> <a href="{{ $result['url'] }}" target="_blank" rel="noopener">{{ $result['url'] }}</a></div>
            <div class="col-md-6"><strong>Folder:</strong> {{ $result['folder'] }}</div>
            <div class="col-md-6"><strong>Database:</strong> {{ $result['database'] }}</div>
            <div class="col-md-6"><strong>DB user:</strong> {{ $result['db_user'] }}</div>
            <div class="col-md-6"><strong>Admin login:</strong> {{ $result['admin_username'] }}</div>
        </div>
        @if(!empty($result['notes']))
            <ul class="small mb-0 mt-2">
                @foreach($result['notes'] as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@endif

<form method="POST" action="{{ route('setup.new.store') }}" class="border rounded p-3 bg-light" id="newSetupForm">
    @csrf
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="project_name">Project name *</label>
            <input id="project_name" type="text" name="project_name" class="form-control"
                   value="{{ old('project_name') }}" placeholder="e.g. Al-Noor Mart" required maxlength="80">
            <div class="form-text">Shown in the new app title / navbar.</div>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="subdomain">Subdomain *</label>
            <div class="input-group">
                <input id="subdomain" type="text" name="subdomain" class="form-control"
                       value="{{ old('subdomain') }}" placeholder="e.g. alnoor" required maxlength="40"
                       pattern="[a-z0-9]([a-z0-9-]*[a-z0-9])?" style="text-transform:lowercase;">
                <span class="input-group-text">.{{ $baseDomain }}</span>
            </div>
            <div class="form-text">Creates site + folder <code>{subdomain}.{{ $baseDomain }}</code>.</div>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="database_name">Database name *</label>
            <input id="database_name" type="text" name="database_name" class="form-control"
                   value="{{ old('database_name') }}" placeholder="e.g. pos_alnoor" required maxlength="40"
                   pattern="[A-Za-z0-9_]+">
            <div class="form-text">Must be different from the live LipSip database.</div>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="db_password">New database password *</label>
            <input id="db_password" type="password" name="db_password" class="form-control" required minlength="8" maxlength="64" autocomplete="new-password">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="admin_username">First admin username *</label>
            <input id="admin_username" type="text" name="admin_username" class="form-control"
                   value="{{ old('admin_username', 'software') }}" required maxlength="50">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="admin_password">First admin password *</label>
            <input id="admin_password" type="password" name="admin_password" class="form-control" required minlength="6" maxlength="64" autocomplete="new-password">
        </div>
        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="1" id="confirm_isolated" name="confirm_isolated" required>
                <label class="form-check-label" for="confirm_isolated">
                    I understand this creates a <strong>separate</strong> copy and will <strong>not</strong> change the current LipSip project or database.
                </label>
            </div>
        </div>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary" @disabled(!$configured) id="newSetupSubmit">
                Create New Project
            </button>
            <span class="small text-muted align-self-center">This can take a few minutes (file copy).</span>
        </div>
    </div>
</form>

<script>
document.getElementById('newSetupForm')?.addEventListener('submit', function () {
    const btn = document.getElementById('newSetupSubmit');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Creating… please wait';
    }
});
document.getElementById('subdomain')?.addEventListener('input', function (e) {
    e.target.value = e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '');
});
</script>
@endsection
