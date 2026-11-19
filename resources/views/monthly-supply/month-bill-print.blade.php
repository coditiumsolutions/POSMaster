<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Monthly Supply Bill — {{ $month }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 13px; color: #111; margin: 16px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .muted { color: #666; margin-bottom: 16px; }
        .block { margin-bottom: 28px; page-break-inside: avoid; }
        .head { display: flex; justify-content: space-between; border-bottom: 1px solid #ccc; padding-bottom: 6px; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #eee; padding: 6px 4px; text-align: left; }
        th { font-size: 12px; color: #444; }
        .r { text-align: right; }
        .totals { margin-top: 8px; font-weight: bold; }
        .toolbar { margin-bottom: 12px; }
        @media print { .toolbar { display: none; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()">Print</button>
        <button onclick="window.close()">Close</button>
    </div>

    <h1>Monthly Supply Bill</h1>
    <div class="muted">
        Period: {{ \Carbon\Carbon::parse($from)->format('d-M-Y') }} – {{ \Carbon\Carbon::parse($to)->format('d-M-Y') }}
    </div>

    @forelse($byCustomer as $block)
        <div class="block">
            <div class="head">
                <div>
                    <strong>{{ optional($block['customer'])->customer_name }}</strong><br>
                    <span class="muted">{{ optional($block['customer'])->customer_code }}
                        @if(optional($block['customer'])->mobile_no) · {{ $block['customer']->mobile_no }}@endif
                    </span>
                </div>
                <div class="r">
                    Invoices: {{ $block['invoices']->count() }}
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="r">Qty supplied</th>
                        <th class="r">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($block['items'] as $item)
                        <tr>
                            <td>{{ $item['product_name'] }}</td>
                            <td class="r">{{ number_format($item['quantity'], 3) }}</td>
                            <td class="r">{{ number_format($item['amount'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="totals">
                Total: Rs. {{ number_format($block['total_amount'], 2) }}
                &nbsp;|&nbsp; Paid: Rs. {{ number_format($block['total_paid'], 2) }}
                &nbsp;|&nbsp; Balance: Rs. {{ number_format($block['total_balance'], 2) }}
            </div>
        </div>
    @empty
        <p>No monthly supply invoices for this month.</p>
    @endforelse

    <script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
