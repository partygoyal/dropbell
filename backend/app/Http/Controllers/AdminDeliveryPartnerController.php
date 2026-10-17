<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminDeliveryPartnerController extends Controller
{
    public function index(): View
    {
        return view('admin.management', ['kind' => 'delivery-partners', 'title' => 'Manage delivery partners', 'record' => null, 'rows' => DB::table('delivery_partners')->leftJoin('delivery_partner_kycs', 'delivery_partner_kycs.delivery_partner_id', '=', 'delivery_partners.id')->select('delivery_partners.*', 'delivery_partner_kycs.status as kyc_status')->selectSub(DB::table('orders')->selectRaw('COUNT(*)')->whereColumn('orders.delivery_partner_id', 'delivery_partners.id')->whereNotIn('status', ['delivered', 'cancelled']), 'open_orders')->orderBy('delivery_partners.name')->paginate(20)]);
    }

    public function edit(int $id): View
    {
        $record = DB::table('delivery_partners')->find($id);
        abort_unless($record, 404);

        return view('admin.management', ['kind' => 'delivery-partners', 'title' => 'Edit delivery partner', 'record' => $record, 'rows' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->persist($request);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        return $this->persist($request, $id);
    }

    private function persist(Request $request, ?int $id = null): RedirectResponse
    {
        $input = $request->validate(['name' => 'required|string|max:120', 'phone' => ['required', 'regex:/^[6-9]\d{9}$/', Rule::unique('delivery_partners')->ignore($id)], 'vehicle' => 'nullable|string|max:120', 'active' => 'required|boolean']);
        DB::transaction(function () use ($id, $input, $request) {
            if ($id) {
                $partner = DB::table('delivery_partners')->where('id', $id)->lockForUpdate()->first();
                abort_unless($partner, 404);
                DB::table('delivery_partners')->where('id', $id)->update($input + ['updated_at' => now()]);
                if ($partner->name !== $input['name'] || $partner->phone !== $input['phone']) {
                    $kyc = DB::table('delivery_partner_kycs')->where('delivery_partner_id', $id)->lockForUpdate()->first();
                    if ($kyc) {
                        DB::table('delivery_partner_kycs')->where('id', $kyc->id)->update(['status' => 'pending', 'revision' => $kyc->revision + 1, 'reviewed_by' => null, 'reviewed_at' => null, 'review_note' => null, 'updated_at' => now()]);
                        DB::table('partner_kyc_events')->insert(['delivery_partner_id' => $id, 'admin_id' => $request->user()->id, 'action' => 'KYC review reset', 'note' => 'Partner name or mobile number changed. Review the documents against the updated details.', 'revision' => $kyc->revision + 1, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            } else {
                DB::table('delivery_partners')->insert($input + ['created_at' => now(), 'updated_at' => now()]);
            }
        });

        return redirect()->route('admin.delivery-partners.index')->with('status', 'Delivery partner saved. Inactive partners cannot receive new assignments.');
    }
}
