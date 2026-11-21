@extends('layouts.app')

@section('title', 'Edit Category')

@section('content')
<div class="container-fluid">
    <h1 class="h3 page-heading invoice-details-heading mb-3">Edit Category</h1>

    <div class="bg-white border rounded p-3">
        <form method="POST" action="{{ route('product-categories.update', $category->uid) }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')

            <div class="invoice-meta-grid invoice-meta-form">
                <div class="invoice-meta-row">
                    <label class="invoice-meta-title" for="category_code">Code *</label>
                    <div class="invoice-meta-field">
                        <input id="category_code" type="text" name="category_code"
                               class="form-control form-control-sm invoice-meta-input"
                               value="{{ old('category_code', $category->category_code) }}" required>
                        @error('category_code')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <label class="invoice-meta-title" for="category_name">Name *</label>
                    <div class="invoice-meta-field">
                        <input id="category_name" type="text" name="category_name"
                               class="form-control form-control-sm invoice-meta-input"
                               value="{{ old('category_name', $category->category_name) }}" required>
                        @error('category_name')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="invoice-meta-row">
                    <label class="invoice-meta-title" for="description">Description</label>
                    <div class="invoice-meta-field">
                        <input id="description" type="text" name="description"
                               class="form-control form-control-sm invoice-meta-input"
                               value="{{ old('description', $category->description) }}">
                        @error('description')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <label class="invoice-meta-title" for="is_active">Active</label>
                    <div class="invoice-meta-field">
                        <input type="hidden" name="is_active" value="0">
                        <input id="is_active" type="checkbox" name="is_active" value="1" class="form-check-input"
                               @checked(old('is_active', $category->is_active) == true || old('is_active') === '1')>
                    </div>
                </div>

                <div class="invoice-meta-row">
                    <label class="invoice-meta-title" for="category_image">Category Image</label>
                    <div class="invoice-meta-field" style="grid-column: 2 / -1;">
                        <input id="category_image" type="file" name="category_image"
                               class="form-control form-control-sm invoice-meta-input"
                               accept="image/png,image/jpeg,image/gif,image/webp">
                        <div class="invoice-meta-sub text-muted">
                            Upload an image for this category (JPG, PNG, GIF, WEBP — max 2MB). Shown on Cash POS.
                        </div>
                        @error('category_image')<div class="text-danger small">{{ $message }}</div>@enderror
                        @if(!empty($category->image_path))
                            <div class="mt-2 d-flex align-items-center gap-3 flex-wrap">
                                <img src="{{ asset($category->image_path) }}"
                                     alt="{{ $category->image_name ?: $category->category_name }}"
                                     style="max-height: 72px; max-width: 180px; object-fit: contain; border: 1px solid #e2e8f0; padding: 4px; background: #fff;">
                                <div class="small text-muted">
                                    {{ $category->image_name ?: basename($category->image_path) }}
                                </div>
                                <label class="small mb-0">
                                    <input type="checkbox" name="remove_category_image" value="1">
                                    Remove current image
                                </label>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="mt-3 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">Save</button>
                <a href="{{ route('product-categories.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>

        <form method="POST" action="{{ route('product-categories.destroy', $category->uid) }}" class="mt-3"
              onsubmit="return confirm('Permanently delete this category? This cannot be undone.');">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger">Delete Category</button>
        </form>
    </div>
</div>
@endsection
