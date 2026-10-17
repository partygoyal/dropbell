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
        $input = $request->validate(['status' => 'nullable|in:draft,pending,approved,suspended']);
        $query = DB::table('vendors')->join('users', 'users.id', '=', 'vendors.user_id')->select('vendors.*', 'users.name as owner_name', 'users.email', 'users.phone');
        if (! empty($input['status'])) {
            $query->where('vendors.status', $input['status']);
        }

        return view('admin.vendors.index', ['vendors' => $query->latest('vendors.id')->paginate(20)->withQueryString()]);
    }

    public function show(int $id): View
    {
        $vendor = DB::table('vendors')->join('users', 'users.id', '=', 'vendors.user_id')->select('vendors.*', 'users.name as owner_name', 'users.email', 'users.phone')->where('vendors.id', $id)->first();
        abort_unless($vendor, 404);

        return view('admin.vendors.show', ['vendor' => $vendor, 'offers' => DB::table('vendor_products')->join('products', 'products.id', '=', 'vendor_products.product_id')->where('vendor_id', $id)->select('vendor_products.*', 'products.name', 'products.pack_size')->paginate(20)]);
    }

    public function review(Request $request, int $id): RedirectResponse
    {
        $input = $request->validate(['revision' => 'required|integer|min:0', 'status' => 'required|in:approved,suspended', 'review_note' => 'required_if:status,suspended|nullable|string|max:1000']);
        DB::transaction(function () use ($request, $id, $input) {
            $vendor = DB::table('vendors')->where('id', $id)->lockForUpdate()->first();
            abort_unless($vendor, 404);
            if ($vendor->revision !== (int) $input['revision']) {
                throw ValidationException::withMessages(['revision' => 'Vendor details changed. Reload and review the latest submission.']);
            }
            if ($input['status'] === 'approved' && (! $vendor->business_name || ! $vendor->pickup_address || ! $vendor->city || ! $vendor->postal_code || ! json_decode($vendor->service_postal_codes ?? '[]', true))) {
                throw ValidationException::withMessages(['status' => 'The vendor must complete onboarding before approval.']);
            }
            DB::table('vendors')->where('id', $id)->update(['status' => $input['status'], 'revision' => $vendor->revision + 1, 'review_note' => $input['review_note'] ?? null, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('status','Vendor status updated.');
    }
}
