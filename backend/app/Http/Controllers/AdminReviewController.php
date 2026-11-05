<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminReviewController extends Controller
{
    public function index(): View
    {
        return view('admin.reviews', ['reviews' => DB::table('product_reviews')->leftJoin('products', 'products.id', '=', 'product_reviews.product_id')->select('product_reviews.*', 'products.name as product_name')->latest('product_reviews.updated_at')->paginate(20)]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $input = $request->validate(['status' => 'required|in:published,rejected', 'revision' => 'required|integer|min:0']);
        $count = DB::table('product_reviews')->where('id', $id)->where('revision', $input['revision'])->update(['status' => $input['status'], 'revision' => $input['revision'] + 1, 'updated_at' => now()]);
        abort_unless($count, 409, 'Review changed. Refresh before moderating.');

        return back()->with('status', 'Review moderation saved.');
    }
}
