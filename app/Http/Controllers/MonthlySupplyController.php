<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerMonthlySupply;
use App\Models\CustomerMonthlySupplyOverride;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceDetail;
use App\Support\CurrentBranch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MonthlySupplyController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Daily supply board: monthly customers + assigned items for selected date.
     */
    public function index(Request $request)
    {
        $supplyDateDisplay = trim((string) $request->get('supply_date', now()->format('d-M-Y')));
        $supplyDate = $this->parseDate($supplyDateDisplay) ?: now()->format('Y-m-d');
        $supplyDateDisplay = Carbon::parse($supplyDate)->format('d-M-Y');

        $customers = Customer::query()
            ->where('is_active', true)
            ->where('customer_type', 'monthly')
            ->orderBy('customer_name')
            ->get();

        $assignments = CustomerMonthlySupply::with('product')
            ->active()
            ->forDate($supplyDate)
            ->whereIn('customer_uid', $customers->pluck('uid'))
            ->get()
            ->groupBy('customer_uid');

        $overrides = CustomerMonthlySupplyOverride::query()
            ->whereDate('override_date', $supplyDate)
            ->whereIn('customer_uid', $customers->pluck('uid'))
            ->get()
            ->keyBy(fn ($o) => $o->customer_uid . ':' . $o->product_uid);

        $suppliedInvoices = SalesInvoice::query()
            ->whereDate('invoice_date', $supplyDate)
            ->where('pos_type', 'Monthly')
            ->whereIn('customer_uid', $customers->pluck('uid'))
            ->where('invoice_status', '!=', 'Cancelled')
            ->get()
            ->keyBy('customer_uid');

        $rows = [];
        foreach ($customers as $customer) {
            $supplies = $assignments->get($customer->uid, collect());
            $lineItems = [];
            $dueTotal = 0.0;
            $hasDeliverable = false;

            foreach ($supplies as $supply) {
                $key = $customer->uid . ':' . $supply->product_uid;
                $override = $overrides->get($key);
                $skipped = $override && (
                    (Schema::hasColumn('customer_monthly_supply_overrides', 'is_skipped') && $override->is_skipped)
                    || (float) $override->quantity <= 0
                );
                $qty = $skipped ? 0.0 : (float) ($override ? $override->quantity : $supply->quantity);
                $rate = (float) ($override && (float) $override->rate > 0 ? $override->rate : $supply->rate);
                if ($rate <= 0 && $supply->product) {
                    $rate = (float) ($supply->product->sale_price ?? 0);
                }
                $lineTotal = round($qty * $rate, 2);
                if (!$skipped && $qty > 0) {
                    $hasDeliverable = true;
                    $dueTotal += $lineTotal;
                }

                $lineItems[] = [
                    'supply_uid' => $supply->uid,
                    'product_uid' => $supply->product_uid,
                    'product_name' => optional($supply->product)->product_name ?: ('#' . $supply->product_uid),
                    'quantity' => $qty,
                    'rate' => $rate,
                    'line_total' => $lineTotal,
                    'skipped' => $skipped,
                    'default_quantity' => (float) $supply->quantity,
                ];
            }

            $invoice = $suppliedInvoices->get($customer->uid);
            $rows[] = [
                'customer' => $customer,
                'items' => $lineItems,
                'due_total' => round($dueTotal, 2),
                'has_deliverable' => $hasDeliverable,
                'already_supplied' => (bool) $invoice,
                'invoice' => $invoice,
            ];
        }

        return view('monthly-supply.index', compact(
            'rows',
            'supplyDate',
            'supplyDateDisplay'
        ));
    }

    /** Assign products to a monthly customer (standing daily order). */
    public function create(Request $request)
    {
        $customerUid = $request->get('customer_uid');
        $customers = Customer::where('is_active', true)
            ->where('customer_type', 'monthly')
            ->orderBy('customer_name')
            ->get();
        $products = Product::where('is_active', true)->orderBy('product_name')->get();
        $existing = collect();
        $customer = null;

        if ($customerUid) {
            $customer = Customer::findOrFail($customerUid);
            $existing = CustomerMonthlySupply::with('product')
                ->where('customer_uid', $customerUid)
                ->orderBy('uid')
                ->get();
        }

        return view('monthly-supply.create', compact('customers', 'products', 'customer', 'existing'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_uid' => 'required|exists:customers,uid',
            'product_uid' => 'required|exists:products,uid',
            'quantity' => 'required|numeric|min:0.001',
            'rate' => 'nullable|numeric|min:0',
            'delivery_charges' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $customer = Customer::findOrFail($validated['customer_uid']);
        if (!$customer->isMonthly()) {
            return back()->withInput()->withErrors([
                'customer_uid' => 'Only customers with type Monthly can be assigned supply products.',
            ]);
        }

        $product = Product::findOrFail($validated['product_uid']);
        $rate = (float) ($validated['rate'] ?? 0);
        if ($rate <= 0) {
            $rate = (float) ($product->sale_price ?? 0);
        }

        $payload = [
            'customer_uid' => (int) $validated['customer_uid'],
            'product_uid' => (int) $validated['product_uid'],
            'day_of_month' => 0, // daily
            'quantity' => (float) $validated['quantity'],
            'rate' => $rate,
            'delivery_charges' => (float) ($validated['delivery_charges'] ?? 0),
            'created_date' => now(),
        ];
        if (Schema::hasColumn('customer_monthly_supplies', 'is_active')) {
            $payload['is_active'] = $request->boolean('is_active', true);
        }

        $exists = CustomerMonthlySupply::where('customer_uid', $payload['customer_uid'])
            ->where('product_uid', $payload['product_uid'])
            ->where('day_of_month', 0)
            ->first();

        if ($exists) {
            $exists->update([
                'quantity' => $payload['quantity'],
                'rate' => $payload['rate'],
                'delivery_charges' => $payload['delivery_charges'],
                'updated_date' => now(),
            ] + (Schema::hasColumn('customer_monthly_supplies', 'is_active')
                ? ['is_active' => $payload['is_active'] ?? true]
                : []));
            $msg = 'Product assignment updated.';
        } else {
            CustomerMonthlySupply::create($payload);
            $msg = 'Product assigned for daily supply.';
        }

        return redirect()
            ->route('monthly-supply.create', ['customer_uid' => $validated['customer_uid']])
            ->with('success', $msg);
    }

    public function edit($uid)
    {
        $supply = CustomerMonthlySupply::with(['customer', 'product'])->findOrFail($uid);
        $customers = Customer::where('is_active', true)
            ->where('customer_type', 'monthly')
            ->orderBy('customer_name')
            ->get();
        $products = Product::where('is_active', true)->orderBy('product_name')->get();

        return view('monthly-supply.edit', compact('supply', 'customers', 'products'));
    }

    public function update(Request $request, $uid)
    {
        $supply = CustomerMonthlySupply::findOrFail($uid);
        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0.001',
            'rate' => 'nullable|numeric|min:0',
            'delivery_charges' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $data = [
            'quantity' => (float) $validated['quantity'],
            'rate' => (float) ($validated['rate'] ?? $supply->rate),
            'delivery_charges' => (float) ($validated['delivery_charges'] ?? 0),
            'day_of_month' => 0,
            'updated_date' => now(),
        ];
        if (Schema::hasColumn('customer_monthly_supplies', 'is_active')) {
            $data['is_active'] = $request->boolean('is_active', true);
        }
        $supply->update($data);

        return redirect()
            ->route('monthly-supply.create', ['customer_uid' => $supply->customer_uid])
            ->with('success', 'Assignment updated.');
    }

    public function destroyAssignment($uid)
    {
        $supply = CustomerMonthlySupply::findOrFail($uid);
        $customerUid = $supply->customer_uid;
        $supply->delete();

        return redirect()
            ->route('monthly-supply.create', ['customer_uid' => $customerUid])
            ->with('success', 'Product removed from daily supply.');
    }

    /** Skip selected customers (all products) for a date. */
    public function skip(Request $request)
    {
        $validated = $request->validate([
            'supply_date' => 'required|string',
            'customer_uids' => 'required|array|min:1',
            'customer_uids.*' => 'integer|exists:customers,uid',
            'product_uid' => 'nullable|integer|exists:products,uid',
        ]);

        $date = $this->parseDate($validated['supply_date']);
        if (!$date) {
            return back()->with('error', 'Supply date is invalid.');
        }

        $productUid = $validated['product_uid'] ?? null;
        $count = 0;

        foreach ($validated['customer_uids'] as $customerUid) {
            $query = CustomerMonthlySupply::active()
                ->forDate($date)
                ->where('customer_uid', $customerUid);
            if ($productUid) {
                $query->where('product_uid', $productUid);
            }
            $supplies = $query->get();

            foreach ($supplies as $supply) {
                $this->upsertOverride(
                    (int) $customerUid,
                    (int) $supply->product_uid,
                    $date,
                    0,
                    (float) $supply->rate,
                    true,
                    'Skipped'
                );
                $count++;
            }
        }

        return redirect()
            ->route('monthly-supply.index', ['supply_date' => Carbon::parse($date)->format('d-M-Y')])
            ->with('success', "Skipped {$count} item(s) for {$date}.");
    }

    /** Mark selected customers as supplied: create unpaid monthly invoices. */
    public function markSupplied(Request $request)
    {
        $validated = $request->validate([
            'supply_date' => 'required|string',
            'customer_uids' => 'required|array|min:1',
            'customer_uids.*' => 'integer|exists:customers,uid',
        ]);

        $date = $this->parseDate($validated['supply_date']);
        if (!$date) {
            return back()->with('error', 'Supply date is invalid.');
        }

        $user = Auth::user();
        $branchUid = CurrentBranch::uid($user);
        $branchName = CurrentBranch::name($user) ?: ($user->branch ?? null);
        $created = 0;
        $skipped = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($validated['customer_uids'] as $customerUid) {
                $customer = Customer::find($customerUid);
                if (!$customer || !$customer->isMonthly()) {
                    $skipped++;
                    continue;
                }

                $existing = SalesInvoice::where('customer_uid', $customerUid)
                    ->whereDate('invoice_date', $date)
                    ->where('pos_type', 'Monthly')
                    ->where('invoice_status', '!=', 'Cancelled')
                    ->first();
                if ($existing) {
                    $skipped++;
                    continue;
                }

                $lines = $this->buildDeliverableLines((int) $customerUid, $date);
                if (empty($lines)) {
                    $skipped++;
                    continue;
                }

                $subTotal = round(collect($lines)->sum('total_amount'), 2);
                $invoiceNo = $this->generateInvoiceNo($date);

                $invoice = SalesInvoice::create([
                    'invoice_no' => $invoiceNo,
                    'invoice_date' => $date . ' 00:00:00',
                    'customer_uid' => $customerUid,
                    'user_uid' => $user->uid,
                    'user_name' => $user->user_name,
                    'branch_uid' => $branchUid,
                    'branch' => $branchName,
                    'sub_total' => $subTotal,
                    'discount_amount' => 0,
                    'tax_amount' => 0,
                    'other_charges' => 0,
                    'net_amount' => $subTotal,
                    'paid_amount' => 0,
                    'balance_amount' => $subTotal,
                    'payment_status' => 'Unpaid',
                    'invoice_status' => 'Completed',
                    'pos_type' => 'Monthly',
                    'remarks' => 'Monthly Supply ' . $date,
                    'created_date' => now(),
                ]);

                foreach ($lines as $line) {
                    SalesInvoiceDetail::create([
                        'sales_invoice_uid' => $invoice->uid,
                        'product_uid' => $line['product_uid'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'discount_percent' => 0,
                        'discount_amount' => 0,
                        'tax_percent' => 0,
                        'tax_amount' => 0,
                        'total_amount' => $line['total_amount'],
                    ]);

                    if ($branchUid) {
                        $this->updateInventory(
                            (int) $line['product_uid'],
                            -1 * (float) $line['quantity'],
                            $invoiceNo,
                            (int) $branchUid
                        );
                    }
                }

                $created++;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return redirect()
                ->route('monthly-supply.index', ['supply_date' => Carbon::parse($date)->format('d-M-Y')])
                ->with('error', 'Mark Supplied failed: ' . $e->getMessage());
        }

        $msg = "Created {$created} unpaid invoice(s) for {$date}.";
        if ($skipped > 0) {
            $msg .= " Skipped {$skipped} (already supplied or no deliverable items).";
        }

        return redirect()
            ->route('monthly-supply.index', ['supply_date' => Carbon::parse($date)->format('d-M-Y')])
            ->with('success', $msg);
    }

    /** Month-end bill list / print. */
    public function monthBill(Request $request)
    {
        $month = trim((string) $request->get('month', now()->format('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }
        $from = $month . '-01';
        $to = Carbon::parse($from)->endOfMonth()->format('Y-m-d');
        $customerUid = $request->get('customer_uid');

        $customers = Customer::monthly()->where('is_active', true)->orderBy('customer_name')->get();

        $query = SalesInvoice::with(['customer', 'details.product'])
            ->where('pos_type', 'Monthly')
            ->whereDate('invoice_date', '>=', $from)
            ->whereDate('invoice_date', '<=', $to)
            ->where('invoice_status', '!=', 'Cancelled')
            ->orderBy('invoice_date')
            ->orderBy('uid');

        if ($customerUid) {
            $query->where('customer_uid', $customerUid);
        }

        $invoices = $query->get();

        $byCustomer = $invoices->groupBy('customer_uid')->map(function ($group) {
            $customer = optional($group->first())->customer;
            $itemTotals = [];
            foreach ($group as $inv) {
                foreach ($inv->details as $d) {
                    $pid = $d->product_uid;
                    if (!isset($itemTotals[$pid])) {
                        $itemTotals[$pid] = [
                            'product_name' => optional($d->product)->product_name ?: ('#' . $pid),
                            'quantity' => 0.0,
                            'amount' => 0.0,
                        ];
                    }
                    $itemTotals[$pid]['quantity'] += (float) $d->quantity;
                    $itemTotals[$pid]['amount'] += (float) $d->total_amount;
                }
            }
            return [
                'customer' => $customer,
                'invoices' => $group,
                'items' => collect($itemTotals)->values(),
                'total_amount' => (float) $group->sum('net_amount'),
                'total_paid' => (float) $group->sum('paid_amount'),
                'total_balance' => (float) $group->sum('balance_amount'),
            ];
        })->values();

        $print = $request->boolean('print');

        return view(
            $print ? 'monthly-supply.month-bill-print' : 'monthly-supply.month-bill',
            compact('byCustomer', 'customers', 'month', 'from', 'to', 'customerUid')
        );
    }

    private function buildDeliverableLines(int $customerUid, string $date): array
    {
        $supplies = CustomerMonthlySupply::with('product')
            ->active()
            ->forDate($date)
            ->where('customer_uid', $customerUid)
            ->get();

        $lines = [];
        foreach ($supplies as $supply) {
            $override = CustomerMonthlySupplyOverride::where('customer_uid', $customerUid)
                ->where('product_uid', $supply->product_uid)
                ->whereDate('override_date', $date)
                ->first();

            $skipped = $override && (
                (Schema::hasColumn('customer_monthly_supply_overrides', 'is_skipped') && $override->is_skipped)
                || (float) $override->quantity <= 0
            );
            if ($skipped) {
                continue;
            }

            $qty = (float) ($override ? $override->quantity : $supply->quantity);
            if ($qty <= 0) {
                continue;
            }

            $rate = (float) ($override && (float) $override->rate > 0 ? $override->rate : $supply->rate);
            if ($rate <= 0 && $supply->product) {
                $rate = (float) ($supply->product->sale_price ?? 0);
            }

            $lines[] = [
                'product_uid' => (int) $supply->product_uid,
                'quantity' => $qty,
                'unit_price' => $rate,
                'total_amount' => round($qty * $rate, 2),
            ];
        }

        return $lines;
    }

    private function upsertOverride(
        int $customerUid,
        int $productUid,
        string $date,
        float $quantity,
        float $rate,
        bool $skipped = false,
        ?string $remarks = null
    ): void {
        $row = CustomerMonthlySupplyOverride::where('customer_uid', $customerUid)
            ->where('product_uid', $productUid)
            ->whereDate('override_date', $date)
            ->first();

        $data = [
            'quantity' => $quantity,
            'rate' => $rate,
            'updated_date' => now(),
        ];
        if (Schema::hasColumn('customer_monthly_supply_overrides', 'is_skipped')) {
            $data['is_skipped'] = $skipped || $quantity <= 0;
        }
        if (Schema::hasColumn('customer_monthly_supply_overrides', 'remarks')) {
            $data['remarks'] = $remarks;
        }

        if ($row) {
            $row->update($data);
        } else {
            CustomerMonthlySupplyOverride::create(array_merge($data, [
                'customer_uid' => $customerUid,
                'product_uid' => $productUid,
                'override_date' => $date,
                'created_date' => now(),
            ]));
        }
    }

    private function updateInventory(int $productUid, float $quantity, string $referenceNo, int $branchUid): void
    {
        $inventory = Inventory::findForProductBranch($productUid, $branchUid);

        if ($inventory) {
            $inventory->quantity_in_stock = (float) $inventory->quantity_in_stock + $quantity;
            $inventory->quantity_available = (float) $inventory->quantity_available + $quantity;
            if ($quantity < 0) {
                $inventory->quantity_out = (float) ($inventory->quantity_out ?? 0) + abs($quantity);
            } else {
                $inventory->quantity_in = (float) ($inventory->quantity_in ?? 0) + $quantity;
            }
            $inventory->updated_date = now();
            $inventory->save();
        } else {
            Inventory::create([
                'product_uid' => $productUid,
                'branch_uid' => $branchUid,
                'warehouse_uid' => null,
                'quantity_in_stock' => $quantity,
                'quantity_available' => $quantity,
                'quantity_reserved' => 0,
                'quantity_in' => $quantity > 0 ? $quantity : 0,
                'quantity_out' => $quantity < 0 ? abs($quantity) : 0,
                'updated_date' => now(),
            ]);
        }

        InventoryTransaction::create([
            'product_uid' => $productUid,
            'warehouse_uid' => null,
            'transaction_type' => $quantity < 0 ? 'Sale' : 'Return',
            'reference_no' => $referenceNo,
            'transaction_date' => now(),
            'quantity' => abs($quantity),
            'remarks' => 'Monthly Supply ' . $referenceNo,
            'created_date' => now(),
        ]);
    }

    private function generateInvoiceNo(string $dateYmd): string
    {
        $date = Carbon::parse($dateYmd)->format('Ymd');
        $lastInvoice = SalesInvoice::where('invoice_no', 'like', "MS{$date}%")
            ->orderBy('invoice_no', 'desc')
            ->first();

        $newNumber = $lastInvoice ? ((int) substr($lastInvoice->invoice_no, -4)) + 1 : 1;

        return 'MS' . $date . str_pad((string) $newNumber, 4, '0', STR_PAD_LEFT);
    }

    private function parseDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        foreach (['d-M-Y', 'Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->format('Y-m-d');
            } catch (\Throwable $e) {
            }
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
