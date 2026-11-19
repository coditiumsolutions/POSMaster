@extends('layouts.app')

@section('title', 'Month-End Bills')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h3 page-heading mb-1">Month-End Monthly Supply Bills</h1>
            <p class="text-muted mb-0">Total of all daily supplied items for the month.</p>
        </div>
        <a href="{{ route('monthly-supply.index') }}" class="btn btn-outline-secondary">Daily Board</a>
    </div>

    <div class="bg-white border rounded p-3 mb-3">
        <form method="GET" action="{{ route('monthly-supply.month-bill') }}" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-0" for="month">Month</label>
                <input type="month" id="month" name="month" class="form-control form-control-sm" value="{{ $month }}">
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-0" for="customer_uid">Customer</label>
                <select id="customer_uid" name="customer_uid" class="form-select form-select-sm">
                    <option value="">All monthly customers</option>
                    @foreach($customers as $c)
                        <option value="{{ $c->uid }}" @selected((string)$customerUid === (string)$c->uid)>
                            {{ $c->customer_name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-secondary">Show</button>
                <a class="btn btn-sm btn-primary"
                   href="{{ route('monthly-supply.month-bill', array_filter(['month' => $month, 'customer_uid' => $customerUid, 'print' => 1])) }}"
                   target="_blank">Print</a>
            </div>
        </form>
    </div>

    @forelse($byCustomer as $block)
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between flex-wrap gap-2">
                <strong>{{ optional($block['customer'])->customer_name ?: 'Customer' }}</strong>
                <span>
                    Total: Rs. {{ number_format($block['total_amount'], 2) }}
                    · Balance: Rs. {{ number_format($block['total_balance'], 2) }}
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="text-end">Qty supplied</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($block['items'] as $item)
                                <tr>
                                    <td>{{ $item['product_name'] }}</td>
                                    <td class="text-end">{{ number_format($item['quantity'], 3) }}</td>
                                    <td class="text-end">{{ number_format($item['amount'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="p-3 border-top small text-muted">
                    Daily invoices: {{ $block['invoices']->count() }}
                    ({{ \Carbon\Carbon::parse($from)->format('d-M-Y') }} – {{ \Carbon\Carbon::parse($to)->format('d-M-Y') }})
                </div>
            </div>
        </div>
    @empty
        <div class="alert alert-light border">No monthly supply invoices for this month.</div>
    @endforelse
</div>
@endsection
