@extends('layouts.app')

@section('title', 'Add Customer Payment')

@section('content')
@php
    $selectedCustomerUid = old('customer_uid', optional($customer)->uid);
    $returnTo = $returnTo ?? (optional($customer)->uid ? route('customers.show', $customer->uid) : route('customer-payments.index'));
    $returnToPath = parse_url($returnTo, PHP_URL_PATH) ?: '/customer-payments';
@endphp
<div class="container-fluid">
    <h1 class="h3 page-heading invoice-details-heading mb-3">Add Customer Payment</h1>

    <div class="bg-white border rounded p-3 mb-3">
        <form method="GET" action="{{ route('customer-payments.create') }}" class="row g-2 align-items-end" id="customerSelectForm">
            <input type="hidden" name="return_to" value="{{ $returnToPath }}">
            <div class="col-md-6 col-lg-5">
                <label class="form-label small mb-0" for="customer_uid_select">Customer *</label>
                <select id="customer_uid_select" name="customer_uid" class="form-select" required>
                    <option value="">Select customer</option>
                    @foreach($customers as $c)
                        <option value="{{ $c->uid }}" @selected((string) $selectedCustomerUid === (string) $c->uid)>
                            {{ $c->customer_name }} ({{ $c->customer_code }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-outline-primary">Load Customer</button>
            </div>
        </form>
    </div>

    @if($customer)
        <div class="bg-white border rounded p-3">
            <form method="POST" action="{{ route('customer-payments.store') }}">
                @csrf
                <input type="hidden" name="customer_uid" value="{{ $customer->uid }}">
                <input type="hidden" name="return_to" value="{{ $returnToPath }}">

                <div class="alert alert-light border mb-3 py-2">
                    Paying from: <strong>{{ $customer->customer_name }}</strong>
                </div>

                <div class="invoice-meta-grid invoice-meta-form">
                    <div class="invoice-meta-row">
                        <label class="invoice-meta-title" for="payment_date">Payment Date *</label>
                        <div class="invoice-meta-field">
                            <input id="payment_date" type="text" name="payment_date"
                                   class="form-control form-control-sm invoice-meta-input report-date-input"
                                   value="{{ old('payment_date', now()->format('d-M-Y')) }}"
                                   placeholder="10-Nov-2026" required autocomplete="off">
                        </div>
                        <label class="invoice-meta-title" for="amount">Payment Amount *</label>
                        <div class="invoice-meta-field">
                            <input id="amount" type="number" step="0.01" min="0.01" name="amount"
                                   class="form-control form-control-sm invoice-meta-input"
                                   value="{{ old('amount') }}" required>
                            <div class="form-text">Money received from customer. It is allocated to invoices below.</div>
                        </div>
                    </div>
                    <div class="invoice-meta-row">
                        <label class="invoice-meta-title" for="payment_mode">Payment Mode *</label>
                        <div class="invoice-meta-field">
                            <select id="payment_mode" name="payment_mode" class="form-select form-select-sm invoice-meta-input" required>
                                @foreach(['Cash', 'Bank Transfer', 'Cheque', 'Card'] as $mode)
                                    <option value="{{ $mode }}" @selected(old('payment_mode', 'Cash') === $mode)>{{ $mode }}</option>
                                @endforeach
                            </select>
                        </div>
                        <label class="invoice-meta-title" for="reference_no">Reference No</label>
                        <div class="invoice-meta-field">
                            <input id="reference_no" type="text" name="reference_no" class="form-control form-control-sm invoice-meta-input" value="{{ old('reference_no') }}">
                        </div>
                    </div>
                    <div class="invoice-meta-row">
                        <label class="invoice-meta-title" for="remarks">Remarks</label>
                        <div class="invoice-meta-field" style="grid-column: 2 / -1;">
                            <input id="remarks" type="text" name="remarks" class="form-control form-control-sm invoice-meta-input" value="{{ old('remarks') }}">
                        </div>
                    </div>
                </div>

                @if($pendingInvoices->isNotEmpty())
                    @php $balanceTotal = (float) $pendingInvoices->sum('balance_amount'); @endphp
                    <div class="mt-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                            <div>
                                <h2 class="h6 mb-0">Allocate to invoices</h2>
                                <div class="small text-muted">Payment Amount is split across invoices (oldest first). Adjust Apply only if you need a different split.</div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnAutoApply">
                                Auto-apply from Amount
                            </button>
                        </div>
                        <div class="table-responsive border rounded">
                            <table class="table table-sm align-middle mb-0" id="applyInvoicesTable">
                                <thead>
                                    <tr>
                                        <th>Invoice</th>
                                        <th>Customer</th>
                                        <th>Invoice Date</th>
                                        <th>Status</th>
                                        <th class="text-end">Balance</th>
                                        <th class="text-end" style="width:150px">Apply</th>
                                        <th style="width:110px"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($pendingInvoices as $invoice)
                                        @php
                                            $invBalance = number_format((float) $invoice->balance_amount, 2, '.', '');
                                            $dbStatus = $invoice->payment_status ?: 'Unpaid';
                                        @endphp
                                        <tr class="invoice-apply-row"
                                            data-balance="{{ $invBalance }}"
                                            data-db-status="{{ $dbStatus }}">
                                            <td>{{ $invoice->invoice_no }}</td>
                                            <td>{{ optional($invoice->customer)->customer_name ?: ($customer->customer_name ?? '—') }}</td>
                                            <td>{{ optional($invoice->invoice_date)->format('d-M-Y') ?: '—' }}</td>
                                            <td>
                                                <span class="badge invoice-status-badge bg-secondary">Unpaid</span>
                                            </td>
                                            <td class="text-end invoice-balance"
                                                data-balance="{{ $invBalance }}">
                                                {{ number_format((float) $invoice->balance_amount, 2) }}
                                            </td>
                                            <td>
                                                <input type="number" step="0.01" min="0"
                                                       max="{{ $invBalance }}"
                                                       name="apply_to_invoices[{{ $invoice->uid }}]"
                                                       class="form-control form-control-sm text-end apply-amount-input"
                                                       placeholder="0.00"
                                                       value="{{ old('apply_to_invoices.'.$invoice->uid) }}">
                                            </td>
                                            <td class="text-nowrap">
                                                <button type="button"
                                                        class="btn btn-outline-primary btn-sm btn-fill-remaining"
                                                        title="Fill remaining balance into Apply">
                                                    Fill remaining
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="table-light fw-semibold">
                                        <td colspan="4" class="text-end">Total</td>
                                        <td class="text-end" id="balanceColumnTotal">{{ number_format($balanceTotal, 2) }}</td>
                                        <td class="text-end" id="applyColumnTotal">0.00</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <div class="small text-muted mt-2">
                            Enter <strong>Payment Amount</strong>, then use <strong>Auto-apply</strong> or <strong>Fill remaining</strong> on each invoice.
                            Apply fields stay editable so you can pay any remaining balance.
                        </div>
                        <div class="small mt-1" id="applyHint"></div>
                    </div>
                @endif

                <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save Payment</button>
                    <a href="{{ $returnTo }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    @else
        <div class="alert alert-light border">Select a customer above to enter payment details.</div>
    @endif
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
    var sel = document.getElementById('customer_uid_select');
    if (sel) sel.addEventListener('change', function () { if (this.value) document.getElementById('customerSelectForm').submit(); });

    var amountInput = document.getElementById('amount');
    var rows = Array.prototype.slice.call(document.querySelectorAll('.invoice-apply-row'));
    var applyTotalEl = document.getElementById('applyColumnTotal');
    var applyHint = document.getElementById('applyHint');
    var autoApplyBtn = document.getElementById('btnAutoApply');
    var manualApplyEdit = false;

    function formatMoney(n) {
        return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function rowBalance(row) {
        return parseFloat(row.getAttribute('data-balance') || '0') || 0;
    }

    function rowApply(row) {
        var input = row.querySelector('.apply-amount-input');
        return parseFloat(input && input.value ? input.value : '0') || 0;
    }

    function rowStatus(row) {
        var balance = rowBalance(row);
        var applied = rowApply(row);
        if (applied <= 0.00001) return 'Unpaid';
        if (applied + 0.00001 >= balance) return 'Paid';
        return 'Partial';
    }

    function syncRowUi(row) {
        var input = row.querySelector('.apply-amount-input');
        var badge = row.querySelector('.invoice-status-badge');
        var balance = rowBalance(row);
        var applied = rowApply(row);

        if (input) {
            input.readOnly = false;
            input.disabled = false;
            if (applied > balance) {
                input.value = balance.toFixed(2);
            }
        }

        var status = rowStatus(row);
        row.classList.toggle('is-paid', status === 'Paid');
        row.classList.toggle('is-partial', status === 'Partial');
        row.classList.toggle('is-unpaid', status === 'Unpaid');

        if (badge) {
            badge.textContent = status;
            badge.className = 'badge invoice-status-badge ' + (
                status === 'Paid' ? 'bg-success' : (status === 'Partial' ? 'bg-warning text-dark' : 'bg-secondary')
            );
        }
    }

    function applySum() {
        return rows.reduce(function (sum, row) {
            return sum + rowApply(row);
        }, 0);
    }

    function refreshApplyTotal() {
        rows.forEach(syncRowUi);
        if (applyTotalEl) applyTotalEl.textContent = formatMoney(applySum());

        if (!applyHint || !amountInput) return;
        var payment = parseFloat(amountInput.value) || 0;
        var total = applySum();
        var diff = Math.round((payment - total) * 100) / 100;
        if (payment <= 0 && total <= 0) {
            applyHint.textContent = '';
            applyHint.className = 'small mt-1 text-muted';
        } else if (diff > 0.009) {
            applyHint.textContent = 'Unallocated payment: Rs. ' + formatMoney(diff) + ' (kept as customer advance / unapplied).';
            applyHint.className = 'small mt-1 text-warning';
        } else if (diff < -0.009) {
            applyHint.textContent = 'Apply total exceeds Payment Amount by Rs. ' + formatMoney(Math.abs(diff)) + '.';
            applyHint.className = 'small mt-1 text-danger';
        } else {
            applyHint.textContent = 'Payment Amount fully allocated to invoices.';
            applyHint.className = 'small mt-1 text-success';
        }
    }

    function fillRemaining(row) {
        var input = row.querySelector('.apply-amount-input');
        var balance = rowBalance(row);
        if (!input || balance <= 0) return;
        input.readOnly = false;
        input.disabled = false;
        input.value = balance.toFixed(2);

        if (amountInput) {
            var payment = parseFloat(amountInput.value) || 0;
            var other = applySum() - balance;
            var needed = Math.round((other + balance) * 100) / 100;
            if (payment < needed) {
                amountInput.value = needed.toFixed(2);
            }
        }

        manualApplyEdit = true;
        refreshApplyTotal();
    }

    function autoApplyFromAmount() {
        if (!amountInput || !rows.length) return;
        var remaining = parseFloat(amountInput.value) || 0;

        rows.forEach(function (row) {
            var input = row.querySelector('.apply-amount-input');
            if (!input) return;
            input.readOnly = false;
            input.disabled = false;
            var balance = rowBalance(row);
            var use = Math.max(0, Math.min(remaining, balance));
            input.value = use > 0 ? use.toFixed(2) : '';
            remaining = Math.round((remaining - use) * 100) / 100;
        });

        manualApplyEdit = false;
        refreshApplyTotal();
    }

    if (amountInput && rows.length) {
        amountInput.addEventListener('input', function () {
            if (!manualApplyEdit) autoApplyFromAmount();
            else refreshApplyTotal();
        });
        amountInput.addEventListener('change', function () {
            if (!manualApplyEdit) autoApplyFromAmount();
            else refreshApplyTotal();
        });
    }

    rows.forEach(function (row) {
        var input = row.querySelector('.apply-amount-input');
        var fillBtn = row.querySelector('.btn-fill-remaining');

        if (input) {
            input.readOnly = false;
            input.disabled = false;
            input.addEventListener('input', function () {
                manualApplyEdit = true;
                refreshApplyTotal();
            });
            input.addEventListener('change', function () {
                manualApplyEdit = true;
                refreshApplyTotal();
            });
            input.addEventListener('blur', refreshApplyTotal);
        }

        if (fillBtn) {
            fillBtn.addEventListener('click', function () {
                fillRemaining(row);
            });
        }
    });

    if (autoApplyBtn) {
        autoApplyBtn.addEventListener('click', autoApplyFromAmount);
    }

    var hasOldApply = rows.some(function (row) { return rowApply(row) > 0; });
    if (!hasOldApply && amountInput && (parseFloat(amountInput.value) || 0) > 0) {
        autoApplyFromAmount();
    } else {
        refreshApplyTotal();
    }
});
</script>
@endpush
