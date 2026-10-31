<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminSubCategoryController extends Controller
{
    public function index(): View
    {
        return $this->screen();
    }

    public function edit(int $id): View
    {
        return $this->screen($id);
    }

    private function screen(?int $id = null): View
    {
        return app(AdminCategoryController::class)->screen('sub-categories', $id);
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
        $input = $request->validate(['category_id' => 'required|integer|exists:categories,id', 'name' => ['required', 'string', 'max:80', Rule::unique('sub_categories')->where('category_id', $request->input('category_id'))->ignore($id)], 'active' => 'required|boolean', 'description' => 'nullable|string|max:300', 'banner' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120', 'remove_banner' => 'nullable|boolean']);
        $banner = $request->file('banner')?->store('category-banners', 'public');
        $removeBanner = $request->boolean('remove_banner');
        unset($input['banner'], $input['remove_banner']);
        if ($banner || $removeBanner) {
            $input['banner_path'] = $banner;
        }
        $oldBanner = null;
        try {
            DB::transaction(function () use ($id, $input, &$oldBanner) {
                if ($id) {
                    $record = DB::table('sub_categories')->where('id', $id)->lockForUpdate()->first();
                    abort_unless($record, 404);
                    $oldBanner = $record->banner_path;
                    if ($record->category_id != (int) $input['category_id'] && DB::table('products')->where('sub_category_id', $id)->exists()) {
                        throw ValidationException::withMessages(['category_id' => 'Reassign the products before changing this sub-category’s parent.']);
                    }
                    DB::table('sub_categories')->where('id', $id)->update($input + ['updated_at' => now()]);
                } else {
                    DB::table('sub_categories')->insert($input + ['created_at' => now(), 'updated_at' => now()]);
                }
            });

        } catch (\Throwable $exception) {
            if ($banner) {
                Storage::disk('public')->delete($banner);
            }
            throw $exception;
        }
        if (($banner || $removeBanner) && $oldBanner) {
            Storage::disk('public')->delete($oldBanner);
        }

        return redirect()->route('admin.sub-categories.index')->with('status', 'Sub-category saved.');
    }
}
