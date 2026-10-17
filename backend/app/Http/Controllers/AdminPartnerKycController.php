<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminPartnerKycController extends Controller
{
    public function show(int $id): View
    {
        $partner = DB::table('delivery_partners')->find($id);
        abort_unless($partner, 404);
        $kyc = DB::table('delivery_partner_kycs')->where('delivery_partner_id', $id)->first();

        return view('admin.partner-kyc', ['partner' => $partner, 'kyc' => $kyc, 'reviewer' => $kyc?->reviewed_by ? DB::table('users')->find($kyc->reviewed_by) : null, 'events' => DB::table('partner_kyc_events')->join('users', 'users.id', '=', 'partner_kyc_events.admin_id')->where('delivery_partner_id', $id)->select('partner_kyc_events.*', 'users.name as admin_name')->latest('partner_kyc_events.id')->get()]);
    }

    public function store(Request $request, int $id): RedirectResponse
    {
        abort_unless(DB::table('delivery_partners')->where('id', $id)->exists(), 404);
        $input = $request->validate(['revision' => 'required|integer|min:0', 'legal_name' => 'required|string|max:120', 'address' => 'required|string|max:255', 'city' => 'required|string|max:120', 'postal_code' => ['required', 'regex:/^[1-9]\d{5}$/'], 'document_type' => 'required|in:passport,voter_id,driving_licence,other', 'reference_last_four' => 'nullable|string|size:4|alpha_num:ascii', 'identity_document' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120', 'address_document' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120', 'licence_document' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120']);
        $uploads = [];
        $oldPaths = [];
        try {
            foreach (['identity', 'address', 'licence'] as $kind) {
                if ($request->hasFile($kind.'_document')) {
                    $uploads[$kind.'_path'] = $request->file($kind.'_document')->store('partners/'.$id, 'kyc');
                }
            }
            DB::transaction(function () use ($request, $id, $input, $uploads, &$oldPaths) {
                DB::table('delivery_partners')->where('id', $id)->lockForUpdate()->first();
                $existing = DB::table('delivery_partner_kycs')->where('delivery_partner_id', $id)->lockForUpdate()->first();
                if ((int) $input['revision'] !== ($existing?->revision ?? 0)) {
                    throw ValidationException::withMessages(['revision' => 'KYC changed while you were editing. Reload the page before submitting.']);
                }
                $paths = [];
                foreach (['identity', 'address', 'licence'] as $kind) {
                    $field = $kind.'_path';
                    $paths[$field] = $uploads[$field] ?? $existing?->{$field};
                    if (isset($uploads[$field]) && $existing?->{$field}) {
                        $oldPaths[] = $existing->{$field};
                    }
                }
                if (! $paths['identity_path'] || ! $paths['address_path']) {
                    throw ValidationException::withMessages(['documents' => 'Upload both identity proof and address proof.']);
                }
                $revision = ($existing?->revision ?? 0) + 1;
                $row = collect($input)->only(['legal_name', 'address', 'city', 'postal_code', 'document_type', 'reference_last_four'])->all() + $paths + ['status' => 'pending', 'revision' => $revision, 'reviewed_by' => null, 'reviewed_at' => null, 'review_note' => null, 'updated_at' => now()];
                DB::table('delivery_partner_kycs')->updateOrInsert(['delivery_partner_id' => $id], $row + ['created_at' => $existing?->created_at ?? now()]);
                DB::table('partner_kyc_events')->insert(['delivery_partner_id' => $id, 'admin_id' => $request->user()->id, 'action' => $existing ? 'KYC resubmitted' : 'KYC submitted', 'note' => 'Documents and details submitted for manual review.', 'revision' => $revision, 'created_at' => now(), 'updated_at' => now()]);
            });
        } catch (\Throwable $exception) {
            foreach ($uploads as $path) {
                Storage::disk('kyc')->delete($path);
            } throw $exception;
        }
        foreach ($oldPaths as $path) {
            Storage::disk('kyc')->delete($path);
        }

        return redirect()->route('admin.partner-kyc.show', $id)->with('status', 'KYC submitted for review. Any previous approval has been reset.');
    }

    public function review(Request $request, int $id): RedirectResponse
    {
        $input = $request->validate(['revision' => 'required|integer|min:1', 'status' => 'required|in:approved,rejected', 'review_note' => 'required_if:status,rejected|nullable|string|max:1000']);
        DB::transaction(function () use ($request, $id, $input) {
            $partner = DB::table('delivery_partners')->where('id', $id)->lockForUpdate()->first();
            abort_unless($partner, 404);
            $kyc = DB::table('delivery_partner_kycs')->where('delivery_partner_id', $id)->lockForUpdate()->first();
            abort_unless($kyc, 404);
            if ($kyc->status !== 'pending' || $kyc->revision !== (int) $input['revision']) {
                throw ValidationException::withMessages(['review' => 'This submission has changed or was already reviewed. Reload the page.']);
            }
            if ($input['status'] === 'approved' && (! Storage::disk('kyc')->exists($kyc->identity_path) || ! Storage::disk('kyc')->exists($kyc->address_path))) {
                throw ValidationException::withMessages(['documents' => 'Required documents are missing. Upload them before approving.']);
            }
            DB::table('delivery_partner_kycs')->where('id', $kyc->id)->update(['status' => $input['status'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => $input['review_note'] ?? null, 'updated_at' => now()]);
            DB::table('partner_kyc_events')->insert(['delivery_partner_id' => $id, 'admin_id' => $request->user()->id, 'action' => 'KYC '.$input['status'], 'note' => $input['review_note'] ?? null, 'revision' => $kyc->revision, 'created_at' => now(), 'updated_at' => now()]);
        });

        return redirect()->route('admin.partner-kyc.show', $id)->with('status', 'KYC '.$input['status'].'.');
    }

    public function document(int $id, string $kind): StreamedResponse
    {
        abort_unless(in_array($kind, ['identity', 'address', 'licence'], true), 404);
        $kyc = DB::table('delivery_partner_kycs')->where('delivery_partner_id', $id)->first();
        abort_unless($kyc, 404);
        $path = $kyc->{$kind.'_path'};
        abort_unless($path && Storage::disk('kyc')->exists($path), 404);

        return Storage::disk('kyc')->download($path,'partner-'.$id.'-'.$kind.'.'.pathinfo($path,PATHINFO_EXTENSION),['Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff']);
    }
}
