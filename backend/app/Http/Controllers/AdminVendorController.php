<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminVendorController extends Controller
{
    public function index(Request $request): View
    {
        $input = $request->validate(['q' => 'nullable|string|max:120', 'status' => 'nullable|in:draft,pending,approved,suspended', 'sort' => 'nullable|in:newest,oldest,name', 'per_page' => 'nullable|integer|in:10,20,50,100,200', 'archived' => 'nullable|boolean']);
        $query = DB::table('vendors')->join('users', 'users.id', '=', 'vendors.user_id')->select('vendors.*', 'users.name as owner_name', 'users.email', 'users.phone');
        $request->boolean('archived') ? $query->whereNotNull('vendors.archived_at') : $query->whereNull('vendors.archived_at');
        if (! empty($input['status'])) {
            $query->where('vendors.status', $input['status']);
        }
        if (! empty($input['q'])) {
            $query->where(function ($search) use ($input) {
                foreach (['vendors.business_name', 'users.name', 'users.email', 'users.phone', 'vendors.city', 'vendors.postal_code'] as $column) {
                    $search->orWhere($column, 'like', '%'.$input['q'].'%');
                }
            });
        }
        match ($input['sort'] ?? 'newest') {
            'name' => $query->orderBy('vendors.business_name')->orderBy('vendors.id'),
            'oldest' => $query->orderBy('vendors.id'),
            default => $query->orderByDesc('vendors.id'),
        };

        return view('admin.vendors.index', ['vendors' => $query->adminPaginate('vendors')->withQueryString(), 'counts' => DB::table('vendors')->whereNull('archived_at')->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')]);
    }

    public function edit(int $id): View
    {
        $vendor = DB::table('vendors')->whereNull('archived_at')->find($id);
        abort_unless($vendor, 404);

        return view('admin.vendors.edit', ['vendor' => $vendor]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $input = $request->validate(['revision' => 'required|integer|min:0', 'business_name' => 'required|string|max:120', 'pickup_address' => 'required|string|max:255', 'city' => 'required|string|max:120', 'postal_code' => ['required', 'regex:/^[1-9]\d{5}$/'], 'service_postal_codes' => 'required|string|max:2000']);
        $codes = array_values(array_unique(array_filter(preg_split('/[\s,]+/', trim($input['service_postal_codes'])))));
        if (! $codes || count($codes) > 100 || collect($codes)->contains(fn ($code) => ! preg_match('/^[1-9]\d{5}$/', $code))) {
            throw ValidationException::withMessages(['service_postal_codes' => 'Enter up to 100 valid six-digit postal codes, separated by commas.']);
        }
        DB::transaction(function () use ($id, $input, $codes) {
            $vendor = DB::table('vendors')->whereNull('archived_at')->where('id', $id)->lockForUpdate()->first();
            abort_unless($vendor, 404);
            if ($vendor->revision !== (int) $input['revision']) {
                throw ValidationException::withMessages(['revision' => 'Vendor details changed. Reload before saving.']);
            }
            DB::table('vendors')->where('id', $id)->update(collect($input)->only(['business_name', 'pickup_address', 'city', 'postal_code'])->all() + ['service_postal_codes' => json_encode($codes), 'revision' => $vendor->revision + 1, 'updated_at' => now()]);
        });

        return redirect()->route('admin.vendors.show', $id)->with('status', 'Vendor details saved.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $input = $request->validate(['revision' => 'required|integer|min:0']);
        DB::transaction(function () use ($request, $id, $input) {
            $vendor = DB::table('vendors')->where('id', $id)->lockForUpdate()->first();
            abort_unless($vendor, 404);
            if ($vendor->archived_at || $vendor->revision !== (int) $input['revision']) {
                throw ValidationException::withMessages(['revision' => 'Vendor changed or was already deleted. Reload the list.']);
            }
            if (DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->where('vendor_id', $id)->whereIn('orders.status', ['received', 'picking', 'out_for_delivery'])->exists()) {
                throw ValidationException::withMessages(['vendor' => 'Complete or cancel this vendor’s open orders before deleting.']);
            }
            DB::table('vendors')->where('id', $id)->update(['status' => 'suspended', 'archived_at' => now(), 'revision' => $vendor->revision + 1, 'review_note' => 'Deleted from active vendor list by admin. Order history retained.', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('status', 'Vendor deleted from the active list. Historical records are retained.');
    }

    public function show(int $id): View
    {
        $vendor = DB::table('vendors')->join('users', 'users.id', '=', 'vendors.user_id')->select('vendors.*', 'users.name as owner_name', 'users.email', 'users.phone')->where('vendors.id', $id)->first();
        abort_unless($vendor, 404);

        return view('admin.vendors.show', ['vendor' => $vendor, 'offers' => DB::table('vendor_products')->join('products', 'products.id', '=', 'vendor_products.product_id')->where('vendor_id', $id)->select('vendor_products.*', 'products.name', 'products.pack_size')->adminPaginate('vendor-products')]);
    }

    public function review(Request $request, int $id): RedirectResponse
    {
        $input = $request->validate(['revision' => 'required|integer|min:0', 'status' => 'required|in:approved,suspended', 'review_note' => 'required_if:status,suspended|nullable|string|max:1000']);
        DB::transaction(function () use ($request, $id, $input) {
            $vendor = DB::table('vendors')->where('id', $id)->lockForUpdate()->first();
            abort_unless($vendor, 404);
            abort_if($vendor->archived_at, 422, 'Deleted vendors cannot be reviewed.');
            if ($vendor->revision !== (int) $input['revision']) {
                throw ValidationException::withMessages(['revision' => 'Vendor details changed. Reload and review the latest submission.']);
            }
            if ($input['status'] === 'approved' && (! $vendor->business_name || ! $vendor->pickup_address || ! $vendor->city || ! $vendor->postal_code || ! json_decode($vendor->service_postal_codes ?? '[]', true))) {
                throw ValidationException::withMessages(['status' => 'The vendor must complete onboarding before approval.']);
            }
            DB::table('vendors')->where('id', $id)->update(['status' => $input['status'], 'revision' => $vendor->revision + 1, 'review_note' => $input['review_note'] ?? null, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('status', 'Vendor status updated.');
    }
}
