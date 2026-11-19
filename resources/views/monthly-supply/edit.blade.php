@extends('layouts.app')

@section('title', 'Edit Assignment')

@section('content')
<div class="container-fluid">
    <h1 class="h3 page-heading mb-3">Edit Daily Assignment</h1>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="bg-white border rounded p-3">
        <div class="mb-3">
            <div class="text-muted small">Customer</div>
            <div class="fw-semibold">{{ optional($supply->customer)->customer_name }}</div>
            <div class="text-muted small mt-2">Product</div>
            <div class="fw-semibold">{{ optional($supply->product)->product_name }}</div>
        </div>
        <form method="POST" action="{{ route('monthly-supply.update', $supply->uid) }}">
            @csrf
            @method('PUT')
            <div class="invoice-meta-grid invoice-meta-form">
                <div class="invoice-meta-row">
                    <label class="invoice-meta-title" for="quantity">Daily qty *</label>
                    <div class="invoice-meta-field">
                        <input id="quantity" type="number" step="0.001" min="0.001" name="quantity"
                               class="form-control form-control-sm invoice-meta-input"
                               value="{{ old('quantity', number_format((float)$supply->quantity, 3, '.', '')) }}" required>
                    </div>
                    <label class="invoice-meta-title" for="rate">Rate *</label>
                    <div class="invoice-meta-field">
                        <input id="rate" type="number" step="0.01" min="0" name="rate"
                               class="form-control form-control-sm invoice-meta-input"
                               value="{{ old('rate', number_format((float)$supply->rate, 2, '.', '')) }}" required>
                    </div>
                </div>
                <div class="invoice-meta-row">
                    <label class="invoice-meta-title" for="delivery_charges">Delivery charges</label>
                    <div class="invoice-meta-field">
                        <input id="delivery_charges" type="number" step="0.01" min="0" name="delivery_charges"
                               class="form-control form-control-sm invoice-meta-input"
                               value="{{ old('delivery_charges', number_format((float)$supply->delivery_charges, 2, '.', '')) }}">
                    </div>
                    <label class="invoice-meta-title" for="is_active">Status</label>
                    <div class="invoice-meta-field">
                        <select id="is_active" name="is_active" class="form-select form-select-sm invoice-meta-input">
                            <option value="1" @selected(old('is_active', $supply->is_active ?? true) == '1')>Active</option>
                            <option value="0" @selected(old('is_active', ($supply->is_active ?? true) ? '1' : '0') == '0')>Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save</button>
                <a href="{{ route('monthly-supply.create', ['customer_uid' => $supply->customer_uid]) }}" class="btn btn-outline-secondary">Back</a>
            </div>
        </form>
    </div>
</div>
@endsection
