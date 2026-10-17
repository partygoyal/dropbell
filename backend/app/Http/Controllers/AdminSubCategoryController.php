<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $record = $id ? DB::table('sub_categories')->find($id) : null;
        if ($id) {
            abort_unless($record, 404);
        }

        return view('admin.management', ['kind' => 'sub-categories', 'title' => $id ? 'Edit sub-category' : 'Manage sub-categories', 'record' => $record, 'rows' => $id ? null : DB::table('sub_categories')->join('categories', 'categories.id', '=', 'sub_categories.category_id')->select('sub_categories.*', 'categories.name as category_name')->orderBy('sub_categories.name')->paginate(20), 'categories' => DB::table('categories')->orderBy('name')->get()]);
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
        $input = $request->validate(['category_id' => 'required|integer|exists:categories,id', 'name' => ['required', 'string', 'max:80', Rule::unique('sub_categories')->where('category_id', $request->input('category_id'))->ignore($id)], 'active' => 'required|boolean']);
        DB::transaction(function () use ($id, $input) {
            if ($id) {
                $record = DB::table('sub_categories')->where('id', $id)->lockForUpdate()->first();
                abort_unless($record, 404);
                if ($record->category_id != (int) $input['category_id'] && DB::table('products')->where('sub_category_id', $id)->exists()) {
                    throw ValidationException::withMessages(['category_id' => 'Reassign the products before changing this sub-category’s parent.']);
                }
                DB::table('sub_categories')->where('id', $id)->update($input + ['updated_at' => now()]);
            } else {
                DB::table('sub_categories')->insert($input + ['created_at' => now(), 'updated_at' => now()]);
            }
        });

        return redirect()->route('admin.sub-categories.index')->with('status','Sub-category saved.');
    }
}
