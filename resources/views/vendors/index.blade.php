@extends('layouts.app')

@section('title', 'Vendors')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h3 page-heading mb-1">Vendors</h1>
            <p class="text-muted mb-0">Manage vendors and pay outstanding purchase invoices.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('vendor-payments.index') }}" class="btn btn-outline-primary">
                <i class="bi bi-wallet2"></i> Vendor Payments
            </a>
            <a href="{{ route('vendors.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Add Vendor
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card">
        <div class="card-body">
            <form method="GET" action="{{ route('vendors.index') }}" class="row g-2 align-items-center mb-3">
                <div class="col-md-5 col-lg-4">
                    <input type="text" name="search" class="form-control"
                           placeholder="Search by name, code, or mobile..."
                           value="{{ request('search') }}">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-secondary">Search</button>
                    @if(request('search'))
                        <a href="{{ route('vendors.index') }}" class="btn btn-outline-secondary">Clear</a>
                    @endif
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Mobile</th>
                            <th class="text-end">Pending Invoices</th>
                            <th class="text-end">Outstanding</th>
                            <th>Status</th>
                            <th class="text-nowrap">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($vendors as $vendor)
                        @php
                            $outstanding = (float) ($vendor->outstanding_balance ?? 0);
                            $pendingCount = (int) ($vendor->pending_invoice_count ?? 0);
                        @endphp
                        <tr>
                            <td>{{ $vendor->vendor_code }}</td>
                            <td>{{ $vendor->vendor_name }}</td>
                            <td>{{ $vendor->mobile_no ?: '—' }}</td>
                            <td class="text-end">{{ $pendingCount }}</td>
                            <td class="text-end {{ $outstanding > 0 ? 'text-danger fw-semibold' : '' }}">
                                Rs. {{ number_format($outstanding, 2) }}
                            </td>
                            <td>
                                <span class="badge bg-{{ $vendor->is_active ? 'success' : 'secondary' }}">
                                    {{ $vendor->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="text-nowrap">
                                <a href="{{ route('vendors.show', $vendor->uid) }}" class="btn btn-sm btn-outline-secondary">View</a>
                                <a href="{{ route('vendor-payments.create', ['vendor_uid' => $vendor->uid, 'return_to' => 'vendor']) }}"
                                   class="btn btn-sm btn-success">Pay</a>
                                <a href="{{ route('vendors.edit', $vendor->uid) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">No vendors found</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3 products-pagination">
                {{ $vendors->links() }}
            </div>
        </div>
    </div>
</div>
@endsection
