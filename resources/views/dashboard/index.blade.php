@extends('layouts.app')

@section('title', 'Dashboard - LipSip POS')

@section('content')
@php
    $topLabels = $topInventoryRemaining->pluck('product_name')->map(fn ($n) => \Illuminate\Support\Str::limit((string) $n, 22))->values();
    $topValues = $topInventoryRemaining->pluck('quantity_in_stock')->map(fn ($q) => (float) $q)->values();
    $lowLabels = $lowInventoryRemaining->pluck('product_name')->map(fn ($n) => \Illuminate\Support\Str::limit((string) $n, 22))->values();
    $lowValues = $lowInventoryRemaining->pluck('quantity_in_stock')->map(fn ($q) => (float) $q)->values();
@endphp
<div class="container-fluid">
    <h1 class="h3 page-heading mb-4">Dashboard</h1>

    <div class="row">
        <div class="col-md-3 mb-4">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5 class="card-title">Today's Sales</h5>
                    <h2 class="mb-0">Rs. {{ number_format($todaySales, 2) }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-4">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title">Month Sales</h5>
                    <h2 class="mb-0">Rs. {{ number_format($monthSales, 2) }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-4">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h5 class="card-title">Today's Purchases</h5>
                    <h2 class="mb-0">Rs. {{ number_format($todayPurchases, 2) }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-4">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <h5 class="card-title">Today's Expenses</h5>
                    <h2 class="mb-0">Rs. {{ number_format($todayExpenses, 2) }}</h2>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Top Inventory Remaining</h5>
                    <a href="{{ route('inventories.index') }}" class="small">View all</a>
                </div>
                <div class="card-body">
                    @if($topInventoryRemaining->isEmpty())
                        <p class="text-muted mb-0 text-center py-4">No stock available</p>
                    @else
                        <canvas id="topInventoryChart" height="280" aria-label="Top inventory remaining bar chart"></canvas>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Lowest Inventory Remaining</h5>
                    <a href="{{ route('inventories.index') }}" class="small">View all</a>
                </div>
                <div class="card-body">
                    @if($lowInventoryRemaining->isEmpty())
                        <p class="text-muted mb-0 text-center py-4">No inventory records</p>
                    @else
                        <canvas id="lowInventoryChart" height="280" aria-label="Lowest inventory remaining bar chart"></canvas>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Recent Sales</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Invoice No</th>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentSales as $sale)
                                <tr>
                                    <td>{{ $sale->invoice_no }}</td>
                                    <td>{{ $sale->customer ? $sale->customer->customer_name : 'Walk-in' }}</td>
                                    <td>Rs. {{ number_format($sale->net_amount, 2) }}</td>
                                    <td>{{ $sale->invoice_date->format('Y-m-d H:i') }}</td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="4" class="text-center">No sales found</td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Low Stock Products</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Current Stock</th>
                                    <th>Min Stock</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($lowStockProducts as $product)
                                <tr>
                                    <td>{{ $product->product_name }}</td>
                                    <td class="text-danger">{{ $product->quantity_in_stock }}</td>
                                    <td>{{ $product->minimum_stock }}</td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="3" class="text-center">All products are well stocked</td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const topLabels = @json($topLabels);
    const topValues = @json($topValues);
    const lowLabels = @json($lowLabels);
    const lowValues = @json($lowValues);

    function makeBarChart(canvasId, labels, values, color) {
        const el = document.getElementById(canvasId);
        if (!el || !labels.length) return;

        new Chart(el, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Qty Remaining',
                    data: values,
                    backgroundColor: color,
                    borderRadius: 4,
                    maxBarThickness: 28
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return 'Qty: ' + Number(ctx.raw).toLocaleString(undefined, {
                                    minimumFractionDigits: 0,
                                    maximumFractionDigits: 3
                                });
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45,
                            font: { size: 10 }
                        },
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { font: { size: 11 } },
                        title: { display: true, text: 'Remaining Qty' }
                    }
                }
            }
        });
    }

    makeBarChart('topInventoryChart', topLabels, topValues, 'rgba(15, 118, 110, 0.75)');
    makeBarChart('lowInventoryChart', lowLabels, lowValues, 'rgba(220, 38, 38, 0.7)');
});
</script>
@endpush
