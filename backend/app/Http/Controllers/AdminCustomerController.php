<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminCustomerController extends Controller
{
    public function index(Request $request): View
    {
        $input = $request->validate(['q' => 'nullable|string|max:120']);
        $query = DB::table('users')->where('is_admin', false)->where('is_vendor', false)->select('users.id', 'users.name', 'users.phone', 'users.created_at')->selectSub(DB::table('orders')->selectRaw('COUNT(*)')->whereColumn('orders.user_id', 'users.id'), 'order_count');
        if (! empty($input['q'])) {
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$input['q'].'%')->orWhere('phone', 'like', '%'.$input['q'].'%')->orWhere('email', 'like', '%'.$input['q'].'%'));
        }

        return view('admin.customers', ['customers' => $query->orderByDesc('users.id')->paginate(20)->withQueryString()]);
    }

    public function show(int $id): View
    {
        $customer = DB::table('users')->where('is_admin', false)->where('is_vendor', false)->select('id', 'name', 'phone', 'created_at')->find($id);
        abort_unless($customer, 404);

        return view('admin.customer-details', ['customer' => $customer, 'addresses' => DB::table('addresses')->where('user_id', $id)->orderByDesc('is_default')->get(), 'orders' => DB::table('orders')->where('user_id', $id)->latest()->paginate(20), 'paidValue' => DB::table('orders')->where('user_id', $id)->where('payment_status', 'paid')->sum('total_paise')]);
    }
}
