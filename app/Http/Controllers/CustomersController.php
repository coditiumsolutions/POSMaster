<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\SalesInvoice;
use Illuminate\Http\Request;

class CustomersController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        $search = $request->get('search');

        $query = Customer::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_code', 'like', "%{$search}%")
                  ->orWhere('mobile_no', 'like', "%{$search}%");
            });
        }

        $customers = $query->orderBy('customer_name')->paginate(50)->withQueryString();

        return view('customers.index', compact('customers'));
    }

    public function create()
    {
        return view('customers.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_code' => 'required|string|max:50|unique:customers',
            'customer_name' => 'required|string|max:200',
            'mobile_no' => 'nullable|string|max:30',
            'phone_no' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:150',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'area' => 'nullable|string|max:100',
            'ntn' => 'nullable|string|max:50',
            'opening_balance' => 'nullable|numeric',
            'credit_limit' => 'nullable|numeric',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'customer_type' => 'nullable|in:regular,monthly',
            'is_active' => 'boolean',
        ]);

        $validated['customer_type'] = $validated['customer_type'] ?? 'regular';
        $validated['created_date'] = now();
        Customer::create($validated);

        return redirect()->route('customers.index')->with('success', 'Customer created successfully!');
    }

    public function show(Request $request, $uid)
    {
        $customer = Customer::findOrFail($uid);
        $tab = $request->get('tab', 'overview');
        $allowedTabs = ['overview', 'others', 'arrears', 'unpaid', 'bills-current', 'bills-previous'];
        if (!in_array($tab, $allowedTabs, true)) {
            $tab = 'overview';
        }

        // Keep cancelled/return/wastage out of normal lists, but always keep
        // invoices that still have an outstanding balance (so unpaid never disappears).
        $invoiceBase = SalesInvoice::query()
            ->where('customer_uid', $customer->uid)
            ->where(function ($q) {
                $q->whereNotIn('invoice_status', ['Cancelled', 'Return', 'Wastage'])
                    ->orWhere('balance_amount', '>', 0);
            });

        // Restore accidental Cancelled status when money is still due.
        SalesInvoice::query()
            ->where('customer_uid', $customer->uid)
            ->where('invoice_status', 'Cancelled')
            ->where('balance_amount', '>', 0)
            ->update(['invoice_status' => 'Completed']);

        $totalSales = (float) (clone $invoiceBase)->sum('net_amount');
        $totalPaidOnInvoices = (float) (clone $invoiceBase)->sum('paid_amount');
        $arrears = (float) (clone $invoiceBase)->where('balance_amount', '>', 0)->sum('balance_amount');
        $advance = (float) ($customer->advance_balance ?? 0);
        $opening = (float) ($customer->opening_balance ?? 0);
        $paymentsTotal = (float) CustomerPayment::where('customer_uid', $customer->uid)->sum('amount');

        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        $unpaidInvoices = (clone $invoiceBase)
            ->where('balance_amount', '>', 0)
            ->orderBy('invoice_date')
            ->orderBy('uid')
            ->get();

        $billsCurrent = (clone $invoiceBase)
            ->with('details')
            ->whereDate('invoice_date', '>=', $monthStart)
            ->whereDate('invoice_date', '<=', $monthEnd)
            ->orderByDesc('invoice_date')
            ->orderByDesc('uid')
            ->get();

        $currentMonthNet = (float) $billsCurrent->sum('net_amount');
        $currentMonthPaid = (float) $billsCurrent->sum('paid_amount');
        $currentMonthBalance = (float) $billsCurrent->sum('balance_amount');
        // Current Month Bill = Arrears + current month balance − Advance
        $currentMonthBillTotal = max(0, $opening + $currentMonthBalance - $advance);

        $billsPrevious = (clone $invoiceBase)
            ->with('details')
            ->whereDate('invoice_date', '<', $monthStart)
            ->orderByDesc('invoice_date')
            ->orderByDesc('uid')
            ->paginate(25, ['*'], 'prev_page')
            ->withQueryString();

        $recentPayments = CustomerPayment::where('customer_uid', $customer->uid)
            ->orderByDesc('payment_date')
            ->orderByDesc('uid')
            ->limit(10)
            ->get();

        $recentInvoices = (clone $invoiceBase)
            ->where(function ($q) {
                $q->where('payment_status', 'Paid')
                    ->orWhere('balance_amount', '<=', 0);
            })
            ->orderByDesc('invoice_date')
            ->orderByDesc('uid')
            ->limit(8)
            ->get();

        return view('customers.show', compact(
            'customer',
            'tab',
            'totalSales',
            'totalPaidOnInvoices',
            'arrears',
            'advance',
            'opening',
            'paymentsTotal',
            'unpaidInvoices',
            'billsCurrent',
            'billsPrevious',
            'recentPayments',
            'recentInvoices',
            'monthStart',
            'monthEnd',
            'currentMonthNet',
            'currentMonthPaid',
            'currentMonthBalance',
            'currentMonthBillTotal'
        ));
    }

    public function edit($uid)
    {
        $customer = Customer::findOrFail($uid);
        return view('customers.edit', compact('customer'));
    }

    public function updateArrears(Request $request, $uid)
    {
        $customer = Customer::findOrFail($uid);

        $validated = $request->validate([
            'opening_balance' => 'nullable|numeric|min:0',
            'advance_balance' => 'nullable|numeric|min:0',
        ], [], [
            'opening_balance' => 'arrears amount',
            'advance_balance' => 'advance amount',
        ]);

        $customer->opening_balance = (float) ($validated['opening_balance'] ?? 0);
        $customer->advance_balance = (float) ($validated['advance_balance'] ?? 0);
        $customer->updated_date = now();
        $customer->save();

        return redirect()
            ->route('customers.show', ['customer' => $customer->uid, 'tab' => 'arrears'])
            ->with('success', 'Arrears and Advance amounts saved. They are included in Current Month bill.');
    }

    public function update(Request $request, $uid)
    {
        $customer = Customer::findOrFail($uid);

        $validated = $request->validate([
            'customer_code' => 'required|string|max:50|unique:customers,customer_code,' . $uid . ',uid',
            'customer_name' => 'required|string|max:200',
            'mobile_no' => 'nullable|string|max:30',
            'phone_no' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:150',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'area' => 'nullable|string|max:100',
            'ntn' => 'nullable|string|max:50',
            'opening_balance' => 'nullable|numeric',
            'advance_balance' => 'nullable|numeric',
            'credit_limit' => 'nullable|numeric',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'customer_type' => 'nullable|in:regular,monthly',
            'is_active' => 'boolean',
        ]);

        $validated['customer_type'] = $validated['customer_type'] ?? 'regular';
        $validated['updated_date'] = now();
        $customer->update($validated);

        return redirect()->route('customers.show', $customer->uid)->with('success', 'Customer updated successfully!');
    }

    public function destroy($uid)
    {
        $customer = Customer::findOrFail($uid);
        $customer->update(['is_active' => false]);
        return redirect()->route('customers.index')->with('success', 'Customer deactivated successfully!');
    }
}
