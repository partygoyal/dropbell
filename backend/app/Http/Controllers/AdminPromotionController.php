<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminPromotionController extends Controller
{
    public function index(): View
    {
        return view('admin.promotions.index', ['promotions' => DB::table('promotions')->orderBy('sort_order')->orderByDesc('id')->adminPaginate('promotions')]);
    }

    public function create(): View
    {
        return view('admin.promotions.edit', ['promotion' => null]);
    }

    public function edit(int $id): View
    {
        $promotion = DB::table('promotions')->find($id);
        abort_unless($promotion, 404);

        return view('admin.promotions.edit', compact('promotion'));
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        return $this->save($request, $id);
    }

    private function save(Request $request, ?int $id = null): RedirectResponse
    {
        if ($id) {
            abort_unless(DB::table('promotions')->where('id', $id)->exists(), 404);
        }
        $request->merge(['code' => $request->filled('code') ? strtoupper(trim($request->input('code'))) : null]);
        $input = $request->validate([
            'title' => 'required|string|max:120', 'description' => 'nullable|string|max:300',
            'code' => ['nullable', 'required_unless:discount_type,none', 'regex:/^[A-Z0-9_-]{3,30}$/', Rule::unique('promotions')->ignore($id)],
            'discount_type' => 'required|in:none,percent,fixed', 'discount_value' => ['required_unless:discount_type,none', 'nullable', 'numeric', 'min:0.01', 'max:100000', 'decimal:0,2', ...($request->input('discount_type') === 'percent' ? ['integer', 'max:100'] : [])],
            'minimum' => 'required|numeric|min:0|max:100000|decimal:0,2', 'maximum_discount' => 'nullable|numeric|min:0.01|max:100000|decimal:0,2',
            'banner' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120', 'remove_banner' => 'sometimes|boolean',
            'active' => 'required|boolean', 'show_banner' => 'required|boolean', 'sort_order' => 'required|integer|min:0|max:9999',
            'starts_at' => 'nullable|date_format:Y-m-d\\TH:i', 'ends_at' => ['nullable', 'date_format:Y-m-d\\TH:i', ...($request->filled('starts_at') ? ['after:starts_at'] : [])],
        ]);
        $row = collect($input)->only(['title', 'description', 'code', 'discount_type', 'active', 'show_banner', 'sort_order'])->all();
        $row['code'] = $input['discount_type'] === 'none' ? null : $input['code'];
        $row['discount_value'] = $input['discount_type'] === 'none' ? 0 : (int) round($input['discount_value'] * ($input['discount_type'] === 'fixed' ? 100 : 1));
        $row['minimum_paise'] = (int) round($input['minimum'] * 100);
        $row['maximum_discount_paise'] = isset($input['maximum_discount']) ? (int) round($input['maximum_discount'] * 100) : null;
        foreach (['starts_at', 'ends_at'] as $field) {
            $row[$field] = empty($input[$field]) ? null : Carbon::createFromFormat('Y-m-d\\TH:i', $input[$field], 'Asia/Kolkata')->utc();
        }
        $banner = $request->file('banner')?->store('promotions', 'public');
        $old = null;
        try {
            DB::transaction(function () use ($id, $row, $banner, $request, &$old) {
                if ($id) {
                    $old = DB::table('promotions')->where('id', $id)->lockForUpdate()->first();
                }
                if ($banner || $request->boolean('remove_banner')) {
                    $row['banner_path'] = $banner;
                }
                if ($id) {
                    DB::table('promotions')->where('id', $id)->update($row + ['updated_at' => now()]);
                } else {
                    DB::table('promotions')->insert($row + ['created_at' => now(), 'updated_at' => now()]);
                }
            });
        } catch (\Throwable $exception) {
            if ($banner) {
                Storage::disk('public')->delete($banner);
            }
            throw $exception;
        }
        if (($banner || $request->boolean('remove_banner')) && $old?->banner_path) {
            Storage::disk('public')->delete($old->banner_path);
        }

        return redirect()->route('admin.promotions.index')->with('status', 'Promotion saved. Active banners appear automatically on the website and customer app.');
    }
}
