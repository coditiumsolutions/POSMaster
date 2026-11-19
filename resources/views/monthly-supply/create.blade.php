@extends('layouts.app')

@section('title', 'Assign Monthly Products')

@section('content')
@php
    $selectedCustomerUid = old('customer_uid', optional($customer)->uid);
@endphp
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h3 page-heading mb-1">Assign Products</h1>
            <p class="text-muted mb-0">Assign daily products to a <strong>Monthly</strong> customer. These items appear on the daily supply board.</p>
        </div>
        <a href="{{ route('monthly-supply.index') }}" class="btn btn-outline-secondary">Daily Board</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="bg-white border rounded p-3 mb-3">
        <form method="GET" action="{{ route('monthly-supply.create') }}" class="row g-2 align-items-end" id="customerPickForm">
            <div class="col-md-6 col-lg-5">
                <label class="form-label small mb-0" for="customer_uid_select">Monthly customer *</label>
                <select id="customer_uid_select" name="customer_uid" class="form-select" required>
                    <option value="">Select customer</option>
                    @foreach($customers as $c)
                        <option value="{{ $c->uid }}" @selected((string)$selectedCustomerUid === (string)$c->uid)>
                            {{ $c->customer_name }} ({{ $c->customer_code }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-outline-primary">Load</button>
            </div>
        </form>
        @if($customers->isEmpty())
            <div class="alert alert-warning mt-3 mb-0">
                No monthly customers yet. Open <a href="{{ route('customers.create') }}">Customers</a> and set type to Monthly.
            </div>
        @endif
    </div>

    @if($customer)
        <div class="row g-3">
            <div class="col-lg-5">
                <div class="bg-white border rounded p-3">
                    <h2 class="h6 mb-3">Add / update product for {{ $customer->customer_name }}</h2>
                    <form method="POST" action="{{ route('monthly-supply.store') }}">
                        @csrf
                        <input type="hidden" name="customer_uid" value="{{ $customer->uid }}">
                        <div class="mb-2">
                            <label class="form-label small mb-0" for="product_uid">Product *</label>
                            <select id="product_uid" name="product_uid" class="form-select form-select-sm" required>
                                <option value="">Select product</option>
                                @foreach($products as $product)
                                    <option value="{{ $product->uid }}"
                                            data-rate="{{ number_format((float)$product->sale_price, 2, '.', '') }}"
                                            @selected(old('product_uid') == $product->uid)>
                                        {{ $product->product_name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small mb-0" for="quantity">Daily qty *</label>
                                <input id="quantity" type="number" step="0.001" min="0.001" name="quantity"
                                       class="form-control form-control-sm" value="{{ old('quantity', '1') }}" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-0" for="rate">Rate</label>
                                <input id="rate" type="number" step="0.01" min="0" name="rate"
                                       class="form-control form-control-sm" value="{{ old('rate') }}"
                                       placeholder="Sale price">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small mb-0" for="delivery_charges">Delivery charges</label>
                            <input id="delivery_charges" type="number" step="0.01" min="0" name="delivery_charges"
                                   class="form-control form-control-sm" value="{{ old('delivery_charges', '0') }}">
                        </div>
                        <input type="hidden" name="is_active" value="1">
                        <button type="submit" class="btn btn-primary">Save Assignment</button>
                    </form>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header"><strong>Current daily assignments</strong></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-end">Qty</th>
                                        <th class="text-end">Rate</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($existing as $row)
                                        <tr>
                                            <td>{{ optional($row->product)->product_name }}</td>
                                            <td class="text-end">{{ number_format((float)$row->quantity, 3) }}</td>
                                            <td class="text-end">{{ number_format((float)$row->rate, 2) }}</td>
                                            <td class="text-nowrap">
                                                <a href="{{ route('monthly-supply.edit', $row->uid) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                                <form method="POST" action="{{ route('monthly-supply.destroy-assignment', $row->uid) }}" class="d-inline"
                                                      onsubmit="return confirm('Remove this product from daily supply?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-3">No products assigned yet</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var pick = document.getElementById('customer_uid_select');
    if (pick) {
        pick.addEventListener('change', function () {
            if (this.value) document.getElementById('customerPickForm').submit();
        });
    }
    var product = document.getElementById('product_uid');
    var rate = document.getElementById('rate');
    if (product && rate) {
        product.addEventListener('change', function () {
            var opt = product.options[product.selectedIndex];
            if (opt && opt.dataset.rate && !rate.value) rate.value = opt.dataset.rate;
        });
    }
});
</script>
@endpush
