<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminCategoryController extends Controller
{
    public function index(): View
    {
        return $this->screen();
    }

    public function edit(int $id): View
    {
        return $this->screen('categories', $id);
    }

    public function screen(string $kind = 'categories', ?int $id = null): View
    {
        $record = $id ? DB::table($kind === 'categories' ? 'categories' : 'sub_categories')->find($id) : null;
        abort_if($id && ! $record, 404);

        return view('admin.categories', [
            'kind' => $kind, 'record' => $record,
            'categories' => DB::table('categories')->orderBy('name')->get(),
            'rows' => DB::table('categories')->orderBy('name')->adminPaginate('categories'),
            'subCategories' => DB::table('sub_categories')->orderBy('name')->get()->groupBy('category_id'),
        ]);
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
        $input = $request->validate(['shopping_section' => 'sometimes|in:food,groceries', 'name' => ['required', 'string', 'max:80', Rule::unique('categories')->ignore($id)], 'active' => 'required|boolean', 'description' => 'nullable|string|max:300', 'banner' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120', 'remove_banner' => 'nullable|boolean']);
        if (strcasecmp($input['name'], 'All') === 0) {
            throw ValidationException::withMessages(['name' => 'All is reserved for browsing the complete catalogue. Choose another category name.']);
        }
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
                    $old = DB::table('categories')->where('id', $id)->lockForUpdate()->first();
                    abort_unless($old, 404);
                    $oldBanner = $old->banner_path;
                    DB::table('products')->where('category', $old->name)->update(['category' => $input['name'], 'updated_at' => now()]);
                    DB::table('categories')->where('id', $id)->update($input + ['updated_at' => now()]);
                } else {
                    DB::table('categories')->insert($input + ['created_at' => now(), 'updated_at' => now()]);
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

        return redirect()->route('admin.categories.index')->with('status', 'Category saved.');
    }
}
