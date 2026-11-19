@extends('layouts.app')
@section('title', 'New Setup')
@section('content')
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 page-heading invoice-details-heading mb-1">New Setup</h1>
        <p class="text-muted mb-0">
            Create a separate POS copy and blank database on Hostinger for another customer.
            The live POSMaster project is never changed.
        </p>
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

@if(($provider ?? 'hostinger') === 'hostinger' && !empty($showHostingerTokenForm))
    <div class="border rounded p-3 mb-4 bg-white">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <div class="fw-semibold">Hostinger API token</div>
            @if(!empty($hostingerTokenSaved))
                <span class="badge text-bg-success">Saved on this master site</span>
            @else
                <span class="badge text-bg-warning">Required</span>
            @endif
        </div>
        @if(!empty($hostingerTokenSaved))
            <p class="small text-muted mb-2">
                A token is already stored in this site’s <code>.env</code>. Paste a new one below only if you need to replace it.
            </p>
        @endif
        <form method="POST" action="{{ route('setup.new.hostinger-token') }}">
            @csrf
            <label class="form-label" for="hostinger_api_token">
                {{ !empty($hostingerTokenSaved) ? 'Replace Hostinger API token' : 'Paste Hostinger API token' }}
            </label>
            <div class="input-group">
                <input id="hostinger_api_token" type="password" name="hostinger_api_token" class="form-control"
                       required minlength="20" maxlength="500" autocomplete="off"
                       placeholder="Paste token from hPanel → API">
                <button type="submit" class="btn btn-outline-primary">
                    {{ !empty($hostingerTokenSaved) ? 'Update token' : 'Save token' }}
                </button>
            </div>
            <div class="form-text">
                Master only (<code>posmaster.coditium.com</code>). Create a token at
                <a href="https://hpanel.hostinger.com/api" target="_blank" rel="noopener">hpanel.hostinger.com/api</a>
                (shown only once). Not used on client sites like medical.
            </div>
        </form>
    </div>
@endif

@if(!$configured)
    <div class="alert alert-warning">
        New Setup is not fully configured yet.
        {{ $configurationHint ?? 'Ask Software to finish Hostinger New Setup configuration.' }}
    </div>
@else
    <div class="alert alert-info py-2 small">
        Provider: <strong>{{ strtoupper($provider ?? 'hostinger') }}</strong>
        — creates website + MySQL database, then deploys POSMaster code.
        @if(!empty($hostingerTokenSaved))
            Hostinger API token is configured.
        @endif
    </div>
@endif

@if(!empty($result))
    <div class="border rounded p-3 mb-4" style="background:#f0fdf4;">
        <h2 class="h6 mb-2">Created successfully</h2>
        <div class="row g-2 small">
            <div class="col-md-6"><strong>Project:</strong> {{ $result['project_name'] }}</div>
            <div class="col-md-6"><strong>URL:</strong> <a href="{{ $result['url'] }}" target="_blank" rel="noopener">{{ $result['url'] }}</a></div>
            <div class="col-md-6"><strong>Folder:</strong> {{ $result['folder'] ?? $result['target_root'] ?? '—' }}</div>
            <div class="col-md-6"><strong>Database:</strong> {{ $result['database'] }}</div>
            <div class="col-md-6"><strong>DB user:</strong> {{ $result['db_user'] }}</div>
            <div class="col-md-6"><strong>Admin login:</strong> {{ $result['admin_username'] }} / {{ $defaultAdminPassword ?? 'Coditium$2020' }}</div>
            <div class="col-md-6"><strong>MySQL password:</strong> {{ $defaultDbPassword ?? 'Coditium$Solutions@2020' }}</div>
        </div>
        @if(!empty($result['steps']))
            <details class="small mt-2">
                <summary>Provisioning steps</summary>
                <ol class="mb-0 mt-1">
                    @foreach($result['steps'] as $step)
                        <li>{{ $step }}</li>
                    @endforeach
                </ol>
            </details>
        @endif
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
            <div class="form-text">Creates Hostinger site <code>{subdomain}.{{ $baseDomain }}</code>.</div>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="database_name">Database name *</label>
            <input id="database_name" type="text" name="database_name" class="form-control"
                   value="{{ old('database_name') }}" placeholder="e.g. pos_alnoor" required maxlength="40"
                   pattern="[A-Za-z0-9_]+">
            <div class="form-text">Hostinger will prefix with the account username automatically.</div>
        </div>
        <div class="col-md-6">
            <div class="border rounded p-3 bg-white h-100">
                <div class="fw-semibold mb-2">Fixed credentials (every new copy)</div>
                <div class="small mb-1"><strong>MySQL password:</strong> <code>{{ $defaultDbPassword }}</code></div>
                <div class="small mb-0">
                    <strong>Admin login:</strong>
                    user <code>{{ $defaultAdminUsername }}</code>,
                    password <code>{{ $defaultAdminPassword }}</code>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="1" id="confirm_isolated" name="confirm_isolated" required>
                <label class="form-check-label" for="confirm_isolated">
                    I understand this creates a <strong>separate</strong> copy and will <strong>not</strong> change the current live project or database.
                </label>
            </div>
        </div>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary" @disabled(!$configured) id="newSetupSubmit">
                Create New Project
            </button>
            <span class="small text-muted align-self-center">This can take several minutes (website + copy + schema).</span>
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
