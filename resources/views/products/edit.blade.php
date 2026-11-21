@extends('layouts.app')

@section('title', 'Edit Product')

@section('content')
<div class="container-fluid">
    <h1 class="h3 page-heading invoice-details-heading mb-3">Edit Product</h1>

    <div class="bg-white border rounded p-3">
        <form method="POST" action="{{ route('products.update', $product->uid) }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')

            <div class="invoice-meta-grid invoice-meta-form">
                <div class="invoice-meta-row">
                    <label class="invoice-meta-title" for="product_code">Code *</label>
                    <div class="invoice-meta-field">
                        <input id="product_code" type="text" name="product_code"
                               class="form-control form-control-sm invoice-meta-input"
                               value="{{ old('product_code', $product->product_code) }}" required>
                        @error('product_code')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <label class="invoice-meta-title" for="product_name">Name *</label>
                    <div class="invoice-meta-field">
                        <input id="product_name" type="text" name="product_name"
                               class="form-control form-control-sm invoice-meta-input"
                               value="{{ old('product_name', $product->product_name) }}" required>
                        @error('product_name')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="invoice-meta-row">
                    <label class="invoice-meta-title" for="category_uid">Category *</label>
                    <div class="invoice-meta-field">
                        <select id="category_uid" name="category_uid" class="form-select form-select-sm invoice-meta-input" required>
                            <option value="">Select category</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->uid }}" @selected(old('category_uid', $product->category_uid) == $category->uid)>
                                    {{ $category->category_name }}
                                </option>
                            @endforeach
                        </select>
                        @error('category_uid')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <label class="invoice-meta-title" for="branch_uid">Branch *</label>
                    <div class="invoice-meta-field">
                        <select id="branch_uid" name="branch_uid" class="form-select form-select-sm invoice-meta-input" required>
                            <option value="">Select branch</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->uid }}" @selected(old('branch_uid', $product->branch_uid) == $branch->uid)>
                                    {{ $branch->branch_name }}
                                </option>
                            @endforeach
                        </select>
                        @error('branch_uid')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="invoice-meta-row">
                    <label class="invoice-meta-title" for="barcode">Barcode</label>
                    <div class="invoice-meta-field">
                        <input id="barcode" type="text" name="barcode"
                               class="form-control form-control-sm invoice-meta-input"
                               value="{{ old('barcode', $product->barcode) }}">
                        @error('barcode')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <label class="invoice-meta-title"></label>
                    <div class="invoice-meta-field"></div>
                </div>

                <div class="invoice-meta-row">
                    <label class="invoice-meta-title" for="purchase_price">Purchase Price *</label>
                    <div class="invoice-meta-field">
                        <input id="purchase_price" type="number" step="0.01" min="0" name="purchase_price"
                               class="form-control form-control-sm invoice-meta-input"
                               value="{{ old('purchase_price', $product->purchase_price) }}" required>
                        @error('purchase_price')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <label class="invoice-meta-title" for="sale_price">Sale Price *</label>
                    <div class="invoice-meta-field">
                        <input id="sale_price" type="number" step="0.01" min="0" name="sale_price"
                               class="form-control form-control-sm invoice-meta-input"
                               value="{{ old('sale_price', $product->sale_price) }}" required>
                        @error('sale_price')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="invoice-meta-row">
                    <label class="invoice-meta-title" for="product_image">Product Icon</label>
                    <div class="invoice-meta-field" style="grid-column: 2 / -1;">
                        <input id="product_image" type="file" name="product_image"
                               class="form-control form-control-sm invoice-meta-input"
                               accept="image/png,image/jpeg,image/gif,image/webp">
                        <div class="invoice-meta-sub text-muted">
                            Upload an icon/image for this product (JPG, PNG, GIF, WEBP — max 2MB). Shown on Cash POS.
                        </div>
                        @error('product_image')<div class="text-danger small">{{ $message }}</div>@enderror
                        @if(!empty($product->image_path))
                            <div class="mt-2 d-flex align-items-center gap-3 flex-wrap">
                                <img src="{{ asset($product->image_path) }}"
                                     alt="{{ $product->image_name ?: $product->product_name }}"
                                     style="max-height: 72px; max-width: 180px; object-fit: contain; border: 1px solid #e2e8f0; padding: 4px; background: #fff;">
                                <div class="small text-muted">
                                    {{ $product->image_name ?: basename($product->image_path) }}
                                </div>
                                <label class="small mb-0">
                                    <input type="checkbox" name="remove_product_image" value="1">
                                    Remove current icon
                                </label>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <input type="hidden" name="is_active" value="{{ $product->is_active ? 1 : 0 }}">

            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Update Product</button>
                <a href="{{ route('products.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
