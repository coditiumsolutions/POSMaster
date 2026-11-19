@extends('layouts.app')

@section('title', 'Monthly Supply')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h3 page-heading mb-1">Monthly Supply — Daily Board</h1>
            <p class="text-muted mb-0">Select customers, then Mark Supplied to create unpaid invoices for the day. Skip when not delivering.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('monthly-supply.create') }}" class="btn btn-outline-primary">
                <i class="bi bi-person-plus"></i> Assign Products
            </a>
            <a href="{{ route('monthly-supply.month-bill') }}" class="btn btn-outline-secondary">
                <i class="bi bi-printer"></i> Month-End Bills
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="bg-white border rounded p-3 mb-3">
        <form method="GET" action="{{ route('monthly-supply.index') }}" class="row g-2 align-items-end">
            <div class="col-md-3 col-lg-2">
                <label class="form-label small mb-0" for="supply_date">Supply date</label>
                <input type="text" id="supply_date" name="supply_date"
                       class="form-control form-control-sm report-date-input"
                       value="{{ $supplyDateDisplay }}" placeholder="16-Nov-2026" autocomplete="off" required>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-secondary">Load</button>
            </div>
        </form>
    </div>

    <form method="POST" id="supplyBoardForm">
        @csrf
        <input type="hidden" name="supply_date" value="{{ $supplyDateDisplay }}">

        <div class="d-flex flex-wrap gap-2 mb-2">
            <button type="submit" class="btn btn-success"
                    formaction="{{ route('monthly-supply.mark-supplied') }}"
                    onclick="return confirmSelected('Mark selected customers as supplied and create unpaid invoices?')">
                <i class="bi bi-check2-all"></i> Mark Supplied
            </button>
            <button type="submit" class="btn btn-outline-warning"
                    formaction="{{ route('monthly-supply.skip') }}"
                    onclick="return confirmSelected('Skip all assigned items for selected customers on this date?')">
                <i class="bi bi-skip-forward"></i> Skip Selected
            </button>
            <span class="text-muted small align-self-center">Date: <strong>{{ $supplyDateDisplay }}</strong></span>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width:42px">
                                    <input type="checkbox" class="form-check-input" id="checkAll" title="Select all">
                                </th>
                                <th>Customer</th>
                                <th>Assigned items (for this day)</th>
                                <th class="text-end">Due today</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $row)
                                @php
                                    $c = $row['customer'];
                                    $canSelect = $row['has_deliverable'] && !$row['already_supplied'];
                                @endphp
                                <tr class="{{ $row['already_supplied'] ? 'table-success' : '' }}">
                                    <td>
                                        @if($canSelect)
                                            <input type="checkbox" class="form-check-input row-check"
                                                   name="customer_uids[]" value="{{ $c->uid }}">
                                        @else
                                            <input type="checkbox" class="form-check-input" disabled>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $c->customer_name }}</div>
                                        <div class="small text-muted">{{ $c->customer_code }}@if($c->mobile_no) · {{ $c->mobile_no }}@endif</div>
                                    </td>
                                    <td>
                                        @if(empty($row['items']))
                                            <span class="text-muted">No products assigned.
                                                <a href="{{ route('monthly-supply.create', ['customer_uid' => $c->uid]) }}">Assign</a>
                                            </span>
                                        @else
                                            <ul class="mb-0 ps-3">
                                                @foreach($row['items'] as $item)
                                                    <li class="{{ $item['skipped'] ? 'text-decoration-line-through text-muted' : '' }}">
                                                        {{ $item['product_name'] }}
                                                        × {{ number_format($item['skipped'] ? $item['default_quantity'] : $item['quantity'], 3) }}
                                                        @ {{ number_format($item['rate'], 2) }}
                                                        @if($item['skipped'])
                                                            <span class="badge text-bg-warning">Skipped</span>
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </td>
                                    <td class="text-end fw-semibold">
                                        {{ $row['has_deliverable'] ? 'Rs. '.number_format($row['due_total'], 2) : '—' }}
                                    </td>
                                    <td>
                                        @if($row['already_supplied'])
                                            <span class="badge bg-success">Supplied</span>
                                            <div class="small">
                                                <a href="{{ route('sales-invoices.show', $row['invoice']->uid) }}">
                                                    {{ $row['invoice']->invoice_no }}
                                                </a>
                                            </div>
                                        @elseif(!$row['has_deliverable'] && !empty($row['items']))
                                            <span class="badge bg-warning text-dark">All skipped</span>
                                        @elseif(empty($row['items']))
                                            <span class="badge bg-secondary">No assignment</span>
                                        @else
                                            <span class="badge bg-secondary">Pending</span>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">
                                        <a href="{{ route('monthly-supply.create', ['customer_uid' => $c->uid]) }}"
                                           class="btn btn-sm btn-outline-primary">Assign</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        No monthly customers found. Set customer type to <strong>Monthly</strong> under Customers, then assign products.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection

@push('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    flatpickr('.report-date-input', { dateFormat: 'd-M-Y', allowInput: true, disableMobile: true });

    var checkAll = document.getElementById('checkAll');
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            document.querySelectorAll('.row-check').forEach(function (cb) {
                cb.checked = checkAll.checked;
            });
        });
    }
});

function confirmSelected(msg) {
    var any = document.querySelectorAll('.row-check:checked').length;
    if (!any) {
        alert('Select at least one customer.');
        return false;
    }
    return confirm(msg);
}
</script>
@endpush
