<?php

namespace App\Http\Controllers;

use App\Services\Inventory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminInventoryController extends Controller
{
    public function index(Request $request): View
    {
        $input = $request->validate(['q' => 'nullable|string|max:120', 'filter' => 'nullable|in:low,out,expiring,expired']);
        $query = DB::table('products');
        $today = today('Asia/Kolkata')->toDateString();
        if (! empty($input['q'])) {
            $query->where('name', 'like', '%'.$input['q'].'%');
        }
        if (($input['filter'] ?? null) === 'low') {
            $query->where('stock_quantity', '>', 0)->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
        }
        if (($input['filter'] ?? null) === 'out') {
            $query->where('stock_quantity', 0);
        }
        if (($input['filter'] ?? null) === 'expiring') {
            $query->where('stock_quantity', '>', 0)->whereBetween('expires_on', [$today, today('Asia/Kolkata')->addDays(7)->toDateString()]);
        }
        if (($input['filter'] ?? null) === 'expired') {
            $query->where('stock_quantity', '>', 0)->where('expires_on', '<', $today);
        }

        return view('admin.inventory.index', ['products' => $query->orderBy('stock_quantity')->orderBy('id')->paginate(20)->withQueryString(), 'today' => $today, 'week' => today('Asia/Kolkata')->addDays(7)->toDateString(), 'lowCount' => DB::table('products')->where('stock_quantity', '>', 0)->whereColumn('stock_quantity', '<=', 'low_stock_threshold')->count(), 'outCount' => DB::table('products')->where('stock_quantity', 0)->count(), 'expiredCount' => DB::table('products')->where('stock_quantity', '>', 0)->where('expires_on', '<', $today)->count(), 'expiringCount' => DB::table('products')->where('stock_quantity', '>', 0)->whereBetween('expires_on', [$today, today('Asia/Kolkata')->addDays(7)->toDateString()])->count()]);
    }

    public function show(int $id): View
    {
        $product = DB::table('products')->find($id);
        abort_unless($product, 404);

        return view('admin.inventory.show', ['product' => $product, 'movements' => DB::table('stock_movements')->leftJoin('users', 'users.id', '=', 'stock_movements.actor_id')->leftJoin('orders', 'orders.id', '=', 'stock_movements.order_id')->where('product_id', $id)->select('stock_movements.*', 'users.name as actor_name', 'orders.reference as order_reference')->orderByDesc('stock_movements.id')->paginate(30), 'today' => today('Asia/Kolkata')->toDateString()]);
    }

    public function adjust(Request $request, int $id): RedirectResponse
    {
        $input = $request->validate(['request_key' => 'required|uuid', 'expected_revision' => 'required|integer|min:0', 'type' => 'required|in:received,damaged,expired,correction_add,correction_remove', 'quantity' => 'required|integer|min:1|max:1000000', 'reason' => 'required|string|max:500']);
        $hash = hash('sha256', json_encode([$id, 'adjust', $input['type'], (int) $input['quantity'], $input['reason']]));
        DB::transaction(function () use ($request, $id, $input, $hash) {
            $product = DB::table('products')->where('id', $id)->lockForUpdate()->first();
            abort_unless($product, 404);
            if ($this->retry($input['request_key'], $hash, $id, $request->user()->id)) {
                return;
            }
            $this->checkRevision($product, (int) $input['expected_revision']);
            if ($input['type'] === 'expired' && ! Inventory::expired($product)) {
                throw ValidationException::withMessages(['type' => 'This stock has not expired. Choose damage or a correction when appropriate.']);
            }
            $delta = in_array($input['type'], ['received', 'correction_add'], true) ? (int) $input['quantity'] : -(int) $input['quantity'];
            Inventory::change($product, $delta, $input['type'], $input['reason'], $request->user()->id, null, $input['request_key'], $hash);
        }, 3);

        return redirect()->route('admin.inventory.show', $id)->with('status', 'Stock adjustment recorded.');
    }

    public function settings(Request $request, int $id): RedirectResponse
    {
        $input = $request->validate(['request_key' => 'required|uuid', 'expected_revision' => 'required|integer|min:0', 'low_stock_threshold' => 'required|integer|min:0|max:1000000', 'expires_on' => 'nullable|date_format:Y-m-d', 'reason' => 'required|string|max:500']);
        $hash = hash('sha256', json_encode([$id, 'settings', (int) $input['low_stock_threshold'], $input['expires_on'] ?? null, $input['reason']]));
        DB::transaction(function () use ($request, $id, $input, $hash) {
            $product = DB::table('products')->where('id', $id)->lockForUpdate()->first();
            abort_unless($product, 404);
            if ($this->retry($input['request_key'], $hash, $id, $request->user()->id)) {
                return;
            }
            $this->checkRevision($product, (int) $input['expected_revision']);
            $new = ['low_stock_threshold' => (int) $input['low_stock_threshold'], 'expires_on' => $input['expires_on'] ?? null];
            $before = ['low_stock_threshold' => $product->low_stock_threshold, 'expires_on' => $product->expires_on];
            DB::table('products')->where('id', $id)->update($new);
            Inventory::change($product, 0, 'settings', $input['reason'], $request->user()->id, null, $input['request_key'], $hash, ['before' => $before, 'after' => $new]);
        }, 3);

        return redirect()->route('admin.inventory.show', $id)->with('status', 'Inventory settings saved.');
    }

    private function checkRevision(object $product, int $expected): void
    {
        if ($product->inventory_revision !== $expected) {
            throw ValidationException::withMessages(['stock' => 'Stock changed while you were editing. Reload the page and review the latest quantity before submitting.']);
        }
    }

    private function retry(string $key, string $hash, int $productId, int $actorId): bool
    {
        $movement = DB::table('stock_movements')->where('request_key',$key)->first();
        if (! $movement) {
            return false;
        }
        if ($movement->product_id !== $productId || $movement->actor_id !== $actorId || $movement->request_hash !== $hash) {
            throw ValidationException::withMessages(['request_key' => 'This submission key has already been used for another adjustment. Reload the page.']);
        }

        return true;
    }
}
