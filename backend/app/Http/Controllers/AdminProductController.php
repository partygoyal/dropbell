<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminProductController extends Controller
{
    public function index(Request $request): View
    {
        $input = $request->validate(['q' => 'nullable|string|max:120']);
        $query = DB::table('products');
        if (! empty($input['q'])) {
            $query->where('name', 'like', '%'.$input['q'].'%');
        }

        return view('admin.products.index', ['products' => $query->orderByDesc('id')->paginate(20)->withQueryString()]);
    }

    public function create(): View
    {
        return view('admin.products.form', ['product' => null] + $this->taxonomy());
    }

    public function edit(int $id): View
    {
        $product = DB::table('products')->find($id);
        abort_unless($product, 404);

        return view('admin.products.form', compact('product') + $this->taxonomy());
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->persist($request);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        return $this->persist($request, $id);
    }

    private function taxonomy(): array
    {
        return ['categories' => DB::table('categories')->orderBy('name')->get(), 'subCategories' => DB::table('sub_categories')->join('categories', 'categories.id', '=', 'sub_categories.category_id')->select('sub_categories.*', 'categories.name as category_name')->orderBy('sub_categories.name')->get()];
    }

    private function persist(Request $request, ?int $id = null): RedirectResponse
    {
        $product = $id ? DB::table('products')->find($id) : null;
        if ($id) {
            abort_unless($product, 404);
        }
        $data = $request->validate([
            'name' => 'required|string|max:255', 'category' => ['required', Rule::exists('categories', 'name')],
            'sub_category_id' => 'nullable|integer|exists:sub_categories,id',
            'pack_size' => 'required|string|max:100', 'price' => ['sometimes', 'numeric', 'min:0.01', 'max:100000', 'regex:/^\d+(\.\d{1,2})?$/'],
            'active' => 'sometimes|boolean',
            'description' => 'nullable|string|max:5000', 'care_instructions' => 'nullable|string|max:3000',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096', 'remove_image' => 'sometimes|boolean',
        ]);
        if (! empty($data['sub_category_id'])) {
            $parent = DB::table('categories')->where('name', $data['category'])->value('id');
            if (! DB::table('sub_categories')->where('id', $data['sub_category_id'])->where('category_id', $parent)->exists()) {
                throw ValidationException::withMessages(['sub_category_id' => 'Choose a sub-category belonging to the selected category.']);
            }
        }
        $row = collect($data)->only(['name', 'category', 'pack_size', 'description', 'care_instructions'])->all();
        $row['sub_category_id'] = $data['sub_category_id'] ?? null;
        $row['price_paise'] = isset($data['price']) ? (int) round((float) $data['price'] * 100) : ($product?->price_paise ?? 0);
        $row['active'] = $request->boolean('active');
        $row['updated_at'] = now();
        $row['image_path'] = $product?->image_path;
        if ($request->boolean('remove_image')) {
            $row['image_path'] = null;
        }
        if ($request->hasFile('image')) {
            $row['image_path'] = $request->file('image')->store('products', 'public');
        }
        try {
            DB::transaction(function () use ($id, $row) {
                if ($id) {
                    $current = DB::table('products')->where('id', $id)->lockForUpdate()->first();
                    abort_unless($current, 404);
                    DB::table('products')->where('id', $id)->update($row);
                } else {
                    DB::table('products')->insert($row + ['stock_quantity' => 0, 'created_at' => now()]);
                }
            }, 3);
        } catch (\Throwable $exception) {
            if ($row['image_path'] && $row['image_path'] !== $product?->image_path) {
                Storage::disk('public')->delete($row['image_path']);
            }
            throw $exception;
        }
        if ($product?->image_path && $row['image_path'] !== $product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }

        return redirect()->route('admin.products.index')->with('status', 'Product saved. The storefront uses the updated catalogue.');
    }
}
