@extends('layouts.app')

@section('title', 'System Setup')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h3 page-heading mb-1">System Setup</h1>
            <p class="text-muted mb-0">Configure navbar and print slip branding.</p>
        </div>
        <a href="{{ route('setup.index') }}" class="btn btn-outline-secondary btn-sm">Back to Setup</a>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="bg-white border rounded p-3">
        <form method="POST" action="{{ route('setup.system.update') }}" enctype="multipart/form-data">
            @csrf

            <div class="invoice-meta-grid invoice-meta-form">
                <div class="invoice-meta-row">
                    <label class="invoice-meta-title" for="top_navbar_title">Top Navbar *</label>
                    <div class="invoice-meta-field">
                        <input id="top_navbar_title" type="text" name="top_navbar_title"
                               class="form-control form-control-sm invoice-meta-input"
                               value="{{ old('top_navbar_title', $settings['top_navbar_title']) }}"
                               placeholder="LipSip POS" required maxlength="100">
                        <div class="invoice-meta-sub text-muted">Shown in the first (dark) top navbar.</div>
                    </div>
                    <label class="invoice-meta-title"></label>
                    <div class="invoice-meta-field"></div>
                </div>

                <div class="invoice-meta-row">
                    <label class="invoice-meta-title" for="print_slip_heading">Print Slip Heading *</label>
                    <div class="invoice-meta-field">
                        <input id="print_slip_heading" type="text" name="print_slip_heading"
                               class="form-control form-control-sm invoice-meta-input"
                               value="{{ old('print_slip_heading', $settings['print_slip_heading']) }}"
                               placeholder="LipSip Mart" required maxlength="150">
                        <div class="invoice-meta-sub text-muted">Main title on POS print slip.</div>
                    </div>
                    <label class="invoice-meta-title" for="print_slip_phone">Print Slip Phone</label>
                    <div class="invoice-meta-field">
                        <input id="print_slip_phone" type="text" name="print_slip_phone"
                               class="form-control form-control-sm invoice-meta-input"
                               value="{{ old('print_slip_phone', $settings['print_slip_phone']) }}"
                               placeholder="0339-5470747" maxlength="50">
                        <div class="invoice-meta-sub text-muted">Phone line under the print slip heading.</div>
                    </div>
                </div>

                <div class="invoice-meta-row">
                    <label class="invoice-meta-title" for="print_slip_image">Print Slip Image</label>
                    <div class="invoice-meta-field" style="grid-column: 2 / -1;">
                        <input id="print_slip_image" type="file" name="print_slip_image"
                               class="form-control form-control-sm invoice-meta-input"
                               accept="image/png,image/jpeg,image/gif,image/webp">
                        <div class="invoice-meta-sub text-muted">Optional logo/image printed above the slip heading (max 2MB).</div>
                        @if(!empty($settings['print_slip_image_url']))
                            <div class="mt-2 d-flex align-items-center gap-3 flex-wrap">
                                <img src="{{ $settings['print_slip_image_url'] }}" alt="Print slip image"
                                     style="max-height: 72px; max-width: 180px; object-fit: contain; border: 1px solid #e2e8f0; padding: 4px; background: #fff;">
                                <label class="small mb-0">
                                    <input type="checkbox" name="remove_print_slip_image" value="1">
                                    Remove current image
                                </label>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary btn-sm">Save System Setup</button>
            </div>
        </form>
    </div>
</div>
@endsection
