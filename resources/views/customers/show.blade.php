@extends('layouts.app')

@section('title', 'Customer — ' . $customer->customer_name)

@section('content')
@php
    $tabs = [
        'overview' => 'Overview',
        'others' => 'Others Details',
        'arrears' => 'Arrears-Advance',
        'unpaid' => 'Unpaid Invoices',
        'bills-current' => 'Current Month',
        'bills-previous' => 'Previous Month',
    ];
@endphp
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h3 page-heading mb-1">{{ $customer->customer_name }}</h1>
            <p class="text-muted mb-0">
                {{ $customer->customer_code }}
                · {{ ucfirst($customer->customer_type ?? 'regular') }}
                @if($customer->mobile_no) · {{ $customer->mobile_no }}@endif
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('customers.edit', $customer->uid) }}" class="btn btn-primary">
                <i class="bi bi-pencil"></i> Edit
            </a>
            <a href="{{ route('customer-payments.create', ['customer_uid' => $customer->uid, 'return_to' => '/customers/'.$customer->uid]) }}" class="btn btn-outline-success">
                Add Payment
            </a>
            <a href="{{ route('customers.index') }}" class="btn btn-outline-secondary">All Customers</a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <ul class="nav nav-tabs mb-3" role="tablist">
        @foreach($tabs as $key => $label)
            <li class="nav-item" role="presentation">
                <a class="nav-link {{ $tab === $key ? 'active' : '' }}"
                   href="{{ route('customers.show', ['customer' => $customer->uid, 'tab' => $key]) }}">
                    {{ $label }}
                </a>
            </li>
        @endforeach
    </ul>

    <div class="bg-white border rounded p-3">
        @if($tab === 'overview')
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <div class="text-muted small">Total Sales</div>
                        <div class="h5 mb-0">Rs. {{ number_format($totalSales, 2) }}</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <div class="text-muted small">Paid on Invoices</div>
                        <div class="h5 mb-0">Rs. {{ number_format($totalPaidOnInvoices, 2) }}</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100" style="background:#fff7f7;">
                        <div class="text-muted small">Arrears (Due)</div>
                        <div class="h5 mb-0 text-danger">Rs. {{ number_format($arrears, 2) }}</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100" style="background:#f0fdf4;">
                        <div class="text-muted small">Advance</div>
                        <div class="h5 mb-0 text-success">Rs. {{ number_format($advance, 2) }}</div>
                    </div>
                </div>
            </div>

            <div class="invoice-meta-grid invoice-meta-form invoice-meta-readonly mb-3">
                <div class="invoice-meta-row">
                    <div class="invoice-meta-title">Code</div>
                    <div class="invoice-meta-field">{{ $customer->customer_code }}</div>
                    <div class="invoice-meta-title">Type</div>
                    <div class="invoice-meta-field">{{ ucfirst($customer->customer_type ?? 'regular') }}</div>
                </div>
                <div class="invoice-meta-row">
                    <div class="invoice-meta-title">Mobile</div>
                    <div class="invoice-meta-field">{{ $customer->mobile_no ?: '—' }}</div>
                    <div class="invoice-meta-title">Status</div>
                    <div class="invoice-meta-field">{{ $customer->is_active ? 'Active' : 'Inactive' }}</div>
                </div>
                <div class="invoice-meta-row">
                    <div class="invoice-meta-title">City / Area</div>
                    <div class="invoice-meta-field">{{ trim(($customer->city ?: '') . ' / ' . ($customer->area ?: ''), ' /') ?: '—' }}</div>
                    <div class="invoice-meta-title">Credit Limit</div>
                    <div class="invoice-meta-field">Rs. {{ number_format((float) $customer->credit_limit, 2) }}</div>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('customers.show', ['customer' => $customer->uid, 'tab' => 'unpaid']) }}" class="btn btn-outline-primary btn-sm">Unpaid Invoices</a>
                <a href="{{ route('customer-payments.create', ['customer_uid' => $customer->uid, 'return_to' => '/customers/'.$customer->uid.'?tab=unpaid']) }}" class="btn btn-outline-success btn-sm">Add Payment</a>
            </div>

        @elseif($tab === 'others')
            <h2 class="h6 mb-3">Other details</h2>
            <div class="invoice-meta-grid invoice-meta-form invoice-meta-readonly">
                <div class="invoice-meta-row">
                    <div class="invoice-meta-title">Phone</div>
                    <div class="invoice-meta-field">{{ $customer->phone_no ?: '—' }}</div>
                    <div class="invoice-meta-title">Email</div>
                    <div class="invoice-meta-field">{{ $customer->email ?: '—' }}</div>
                </div>
                <div class="invoice-meta-row">
                    <div class="invoice-meta-title">NTN</div>
                    <div class="invoice-meta-field">{{ $customer->ntn ?: '—' }}</div>
                    <div class="invoice-meta-title">Discount %</div>
                    <div class="invoice-meta-field">{{ number_format((float) $customer->discount_percent, 2) }}%</div>
                </div>
                <div class="invoice-meta-row">
                    <div class="invoice-meta-title">City</div>
                    <div class="invoice-meta-field">{{ $customer->city ?: '—' }}</div>
                    <div class="invoice-meta-title">Area</div>
                    <div class="invoice-meta-field">{{ $customer->area ?: '—' }}</div>
                </div>
                <div class="invoice-meta-row">
                    <div class="invoice-meta-title">Address</div>
                    <div class="invoice-meta-field" style="grid-column: 2 / -1;">{{ $customer->address ?: '—' }}</div>
                </div>
                <div class="invoice-meta-row">
                    <div class="invoice-meta-title">Created</div>
                    <div class="invoice-meta-field">{{ optional($customer->created_date)->format('d-M-Y H:i') ?: '—' }}</div>
                    <div class="invoice-meta-title">Updated</div>
                    <div class="invoice-meta-field">{{ optional($customer->updated_date)->format('d-M-Y H:i') ?: '—' }}</div>
                </div>
            </div>
            <div class="mt-3">
                <a href="{{ route('customers.edit', $customer->uid) }}" class="btn btn-primary">Edit these details</a>
            </div>

        @elseif($tab === 'arrears')
            <h2 class="h6 mb-3">Arrears &amp; Advance</h2>
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <div class="border rounded p-3" style="background:#fff7f7;">
                        <div class="text-muted small">Invoice Arrears (unpaid balances)</div>
                        <div class="h5 mb-0 text-danger">Rs. {{ number_format($arrears, 2) }}</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3">
                        <div class="text-muted small">Payments Recorded</div>
                        <div class="h5 mb-0">Rs. {{ number_format($paymentsTotal, 2) }}</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3">
                        <div class="text-muted small">Credit Limit</div>
                        <div class="h5 mb-0">Rs. {{ number_format((float) $customer->credit_limit, 2) }}</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3">
                        <div class="text-muted small">Current Month Bill (preview)</div>
                        <div class="h5 mb-0">Rs. {{ number_format($currentMonthBillTotal, 2) }}</div>
                        <div class="small text-muted">Arrears + Month balance − Advance</div>
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('customers.update-arrears', $customer->uid) }}" class="border rounded p-3 mb-3 bg-light">
                @csrf
                <h3 class="h6 mb-3">Set amounts for Current Month Bill</h3>
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small mb-0" for="opening_balance">Arrears Amount</label>
                        <input id="opening_balance" type="number" step="0.01" min="0" name="opening_balance"
                               class="form-control form-control-sm @error('opening_balance') is-invalid @enderror"
                               value="{{ old('opening_balance', number_format((float) $opening, 2, '.', '')) }}"
                               placeholder="0.00">
                        <div class="form-text">Brought-forward arrears added to Current Month bill.</div>
                        @error('opening_balance')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-0" for="advance_balance">Advance Amount</label>
                        <input id="advance_balance" type="number" step="0.01" min="0" name="advance_balance"
                               class="form-control form-control-sm @error('advance_balance') is-invalid @enderror"
                               value="{{ old('advance_balance', number_format((float) $advance, 2, '.', '')) }}"
                               placeholder="0.00">
                        <div class="form-text">Advance deducted from Current Month bill.</div>
                        @error('advance_balance')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary btn-sm">Save Arrears &amp; Advance</button>
                        <a href="{{ route('customers.show', ['customer' => $customer->uid, 'tab' => 'bills-current']) }}" class="btn btn-outline-secondary btn-sm">View Current Month</a>
                    </div>
                </div>
            </form>

            <div class="mt-2">
                <a href="{{ route('customer-payments.create', ['customer_uid' => $customer->uid, 'return_to' => '/customers/'.$customer->uid.'?tab=arrears']) }}" class="btn btn-success">Receive Payment</a>
            </div>

        @elseif($tab === 'unpaid')
            @php $unpaidReturn = '/customers/'.$customer->uid.'?tab=unpaid'; @endphp
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                <h2 class="h6 mb-0">Unpaid Invoices</h2>
                <a href="{{ route('customer-payments.create', ['customer_uid' => $customer->uid, 'return_to' => $unpaidReturn]) }}" class="btn btn-success btn-sm">Receive Payment</a>
            </div>
            <div class="table-responsive border rounded mb-4">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th class="text-end">Net</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Balance</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($unpaidInvoices as $inv)
                            <tr>
                                <td>
                                    <a href="{{ route('sales-invoices.show', $inv->uid) }}?return_to={{ urlencode($unpaidReturn) }}">{{ $inv->invoice_no }}</a>
                                </td>
                                <td>{{ optional($inv->invoice_date)->format('d-M-Y') }}</td>
                                <td>{{ $inv->pos_type ?: '—' }}</td>
                                <td class="text-end">{{ number_format((float) $inv->net_amount, 2) }}</td>
                                <td class="text-end">{{ number_format((float) $inv->paid_amount, 2) }}</td>
                                <td class="text-end text-danger fw-semibold">{{ number_format((float) $inv->balance_amount, 2) }}</td>
                                <td>
                                    <span class="badge bg-{{ ($inv->payment_status === 'Partial') ? 'warning text-dark' : 'secondary' }}">
                                        {{ $inv->payment_status ?: 'Unpaid' }}
                                    </span>
                                </td>
                                <td class="text-nowrap">
                                    <a href="{{ route('customer-payments.create', ['customer_uid' => $customer->uid, 'return_to' => $unpaidReturn]) }}" class="btn btn-sm btn-outline-success">Pay</a>
                                    <a href="{{ route('sales-invoices.show', $inv->uid) }}?return_to={{ urlencode($unpaidReturn) }}" class="btn btn-sm btn-outline-secondary">Open</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">No unpaid invoices</td></tr>
                        @endforelse
                    </tbody>
                    @if($unpaidInvoices->isNotEmpty())
                        <tfoot>
                            <tr class="table-light fw-semibold">
                                <td colspan="3" class="text-end">Totals</td>
                                <td class="text-end">{{ number_format((float) $unpaidInvoices->sum('net_amount'), 2) }}</td>
                                <td class="text-end">{{ number_format((float) $unpaidInvoices->sum('paid_amount'), 2) }}</td>
                                <td class="text-end text-danger">{{ number_format((float) $unpaidInvoices->sum('balance_amount'), 2) }}</td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            <div class="row g-3">
                <div class="col-lg-7">
                    <h2 class="h6">Recent bills</h2>
                    <div class="table-responsive border rounded">
                        <table class="table table-sm mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Date</th>
                                    <th class="text-end">Net</th>
                                    <th class="text-end">Balance</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentInvoices as $inv)
                                    <tr>
                                        <td>
                                            <a href="{{ route('sales-invoices.show', $inv->uid) }}?return_to={{ urlencode($unpaidReturn) }}">{{ $inv->invoice_no }}</a>
                                        </td>
                                        <td>{{ optional($inv->invoice_date)->format('d-M-Y') }}</td>
                                        <td class="text-end">{{ number_format((float) $inv->net_amount, 2) }}</td>
                                        <td class="text-end {{ (float) $inv->balance_amount > 0 ? 'text-danger fw-semibold' : '' }}">
                                            {{ number_format((float) $inv->balance_amount, 2) }}
                                        </td>
                                        <td>{{ $inv->payment_status ?: '—' }}</td>
                                        <td class="text-nowrap">
                                            @if((float) $inv->paid_amount > 0 || strcasecmp((string) $inv->payment_status, 'Paid') === 0 || strcasecmp((string) $inv->payment_status, 'Partial') === 0)
                                                <form method="POST" action="{{ route('sales-invoices.mark-unpaid', $inv->uid) }}" class="d-inline"
                                                      onsubmit="return confirm('Mark {{ $inv->invoice_no }} as Unpaid?');">
                                                    @csrf
                                                    <input type="hidden" name="return_to" value="{{ $unpaidReturn }}">
                                                    <button type="submit" class="btn btn-sm btn-outline-warning">Mark Unpaid</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-3">No bills yet</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="col-lg-5">
                    <h2 class="h6">Recent payments</h2>
                    <div class="table-responsive border rounded">
                        <table class="table table-sm mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>Payment</th>
                                    <th>Date</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentPayments as $pay)
                                    <tr>
                                        <td>{{ $pay->payment_no }}</td>
                                        <td>{{ optional($pay->payment_date)->format('d-M-Y') }}</td>
                                        <td class="text-end">{{ number_format((float) $pay->amount, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-center text-muted py-3">No payments yet</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        @elseif($tab === 'bills-current')
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                <h2 class="h6 mb-0">Current Month</h2>
                <span class="text-muted small">
                    {{ \Carbon\Carbon::parse($monthStart)->format('d-M-Y') }} – {{ \Carbon\Carbon::parse($monthEnd)->format('d-M-Y') }}
                </span>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <div class="text-muted small">Arrears Amount</div>
                        <div class="h5 mb-0 text-danger">Rs. {{ number_format($opening, 2) }}</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <div class="text-muted small">Current Month Balance</div>
                        <div class="h5 mb-0">Rs. {{ number_format($currentMonthBalance, 2) }}</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100" style="background:#f0fdf4;">
                        <div class="text-muted small">Advance Amount</div>
                        <div class="h5 mb-0 text-success">Rs. {{ number_format($advance, 2) }}</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100" style="background:#eff6ff;">
                        <div class="text-muted small">Current Month Bill Total</div>
                        <div class="h5 mb-0 fw-bold">Rs. {{ number_format($currentMonthBillTotal, 2) }}</div>
                        <div class="small text-muted">Arrears + Month balance − Advance</div>
                    </div>
                </div>
            </div>
            <div class="mb-3">
                <a href="{{ route('customers.show', ['customer' => $customer->uid, 'tab' => 'arrears']) }}" class="btn btn-sm btn-outline-primary">Edit Arrears / Advance</a>
            </div>

            <div class="table-responsive border rounded">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th class="text-end">Net</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Balance</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($billsCurrent as $inv)
                            <tr>
                                <td>{{ $inv->invoice_no }}</td>
                                <td>{{ optional($inv->invoice_date)->format('d-M-Y') }}</td>
                                <td>{{ $inv->pos_type ?: '—' }}</td>
                                <td class="text-end">{{ number_format((float) $inv->net_amount, 2) }}</td>
                                <td class="text-end">{{ number_format((float) $inv->paid_amount, 2) }}</td>
                                <td class="text-end">{{ number_format((float) $inv->balance_amount, 2) }}</td>
                                <td>{{ $inv->payment_status ?: '—' }}</td>
                                <td>
                                    <a href="{{ route('sales-invoices.show', $inv->uid) }}?return_to={{ urlencode('/customers/'.$customer->uid.'?tab=bills-current') }}" class="btn btn-sm btn-outline-secondary">Open</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">No bills in the current month</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <th colspan="3" class="text-end">Invoice totals</th>
                            <th class="text-end">{{ number_format($currentMonthNet, 2) }}</th>
                            <th class="text-end">{{ number_format($currentMonthPaid, 2) }}</th>
                            <th class="text-end">{{ number_format($currentMonthBalance, 2) }}</th>
                            <th colspan="2"></th>
                        </tr>
                        <tr>
                            <th colspan="5" class="text-end">Arrears Amount</th>
                            <th class="text-end text-danger">{{ number_format($opening, 2) }}</th>
                            <th colspan="2"></th>
                        </tr>
                        <tr>
                            <th colspan="5" class="text-end">Less Advance Amount</th>
                            <th class="text-end text-success">{{ number_format($advance, 2) }}</th>
                            <th colspan="2"></th>
                        </tr>
                        <tr class="table-primary">
                            <th colspan="5" class="text-end">Current Month Bill Total</th>
                            <th class="text-end">{{ number_format($currentMonthBillTotal, 2) }}</th>
                            <th colspan="2"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>

        @elseif($tab === 'bills-previous')
            <h2 class="h6 mb-2">Previous Month</h2>
            <div class="table-responsive border rounded">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th class="text-end">Net</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Balance</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($billsPrevious as $inv)
                            <tr>
                                <td>{{ $inv->invoice_no }}</td>
                                <td>{{ optional($inv->invoice_date)->format('d-M-Y') }}</td>
                                <td>{{ $inv->pos_type ?: '—' }}</td>
                                <td class="text-end">{{ number_format((float) $inv->net_amount, 2) }}</td>
                                <td class="text-end">{{ number_format((float) $inv->paid_amount, 2) }}</td>
                                <td class="text-end">{{ number_format((float) $inv->balance_amount, 2) }}</td>
                                <td>{{ $inv->payment_status ?: '—' }}</td>
                                <td>
                                    <a href="{{ route('sales-invoices.show', $inv->uid) }}?return_to={{ urlencode('/customers/'.$customer->uid) }}" class="btn btn-sm btn-outline-secondary">Open</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">No previous bills</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($billsPrevious->hasPages())
                <div class="mt-3">{{ $billsPrevious->appends(['tab' => 'bills-previous'])->links() }}</div>
            @endif
        @endif
    </div>
</div>
@endsection
