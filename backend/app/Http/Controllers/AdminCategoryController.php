<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminCategoryController extends Controller
{
    public function index(): View
    {
        return view('admin.management', ['kind' => 'categories', 'title' => 'Manage categories', 'rows' => DB::table('categories')->orderBy('name')->paginate(20), 'record' => null]);
    }

    public function edit(int $id): View
    {
        $record = DB::table('categories')->find($id);
        abort_unless($record, 404);

        return view('admin.management', ['kind' => 'categories', 'title' => 'Edit category', 'rows' => null, 'record' => $record]);
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
        $input = $request->validate(['name' => ['required', 'string', 'max:80', Rule::unique('categories')->ignore($id)], 'active' => 'required|boolean']);
        if (strcasecmp($input['name'], 'All') === 0) {
            throw ValidationException::withMessages(['name' => 'All is reserved for browsing the complete catalogue. Choose another category name.']);
        }
        DB::transaction(function () use ($id, $input) {
            if ($id) {
                $old = DB::table('categories')->where('id', $id)->lockForUpdate()->first();
                abort_unless($old, 404);
                DB::table('products')->where('category', $old->name)->update(['category' => $input['name'], 'updated_at' => now()]);
                DB::table('categories')->where('id', $id)->update($input + ['updated_at' => now()]);
            } else {
                DB::table('categories')->insert($input + ['created_at' => now(), 'updated_at' => now()]);
            }
        });

        return redirect()->route('admin.categories.index')->with('status', 'Category saved.');
    }
}
