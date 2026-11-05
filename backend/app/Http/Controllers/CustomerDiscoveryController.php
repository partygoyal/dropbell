<?php

namespace App\Http\Controllers;

use App\Services\Marketplace;
use App\Services\MenuChoices;
use App\Services\ServiceLocations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerDiscoveryController extends Controller
{
    public function favourites(Request $request): JsonResponse
    {
        $rows = DB::table('customer_favourites')->where('user_id', $request->user()->id)->latest('id')->limit(200)->get()->map(function ($row) {
            $target = DB::table($row->kind === 'product' ? 'products' : 'vendors')->find($row->target_id);

            return ['kind' => $row->kind, 'target_id' => $row->target_id, 'name' => $row->kind === 'product' ? ($target?->name ?? 'Unavailable product') : ($target?->business_name ?? 'Unavailable shop'), 'available' => $target && ($row->kind === 'product' ? $target->active : $target->status === 'approved')];
        });

        return response()->json(['data' => $rows])->header('Cache-Control', 'private, no-store');
    }

    public function favourite(Request $request): JsonResponse
    {
        $input = $request->validate(['kind' => 'required|in:product,vendor', 'target_id' => 'required|integer|min:1', 'saved' => 'required|boolean']);
        $identity = ['user_id' => $request->user()->id, 'kind' => $input['kind'], 'target_id' => $input['target_id']];
        if ($input['saved']) {
            abort_unless(DB::table($input['kind'] === 'product' ? 'products' : 'vendors')->where('id', $input['target_id'])->exists(), 404);
            abort_if(DB::table('customer_favourites')->where('user_id', $request->user()->id)->count() >= 200 && ! DB::table('customer_favourites')->where($identity)->exists(), 422, 'Save up to 200 favourites.');
            DB::table('customer_favourites')->insertOrIgnore($identity + ['created_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('customer_favourites')->where($identity)->delete();
        }

        return $this->favourites($request);
    }

    public function review(Request $request, string $reference): JsonResponse
    {
        $input = $request->validate(['product_id' => 'required|integer', 'rating' => 'required|integer|min:1|max:5', 'comment' => 'required|string|min:5|max:1000']);
        DB::transaction(function () use ($request, $reference, $input): void {
            $order = DB::table('orders')->where('reference', $reference)->where('user_id', $request->user()->id)->lockForUpdate()->first();
            abort_unless($order, 404);
            abort_unless($order->status === 'delivered' && ! $order->is_sample, 422, 'Only delivered purchases can be reviewed.');
            $item = DB::table('order_items')->where('order_id', $order->id)->where('product_id', $input['product_id'])->first();
            abort_unless($item, 422, 'Review a product from this order.');
            $existing = DB::table('product_reviews')->where('order_id', $order->id)->where('product_id', $input['product_id'])->first();
            DB::table('product_reviews')->updateOrInsert(['order_id' => $order->id, 'product_id' => $input['product_id']], ['user_id' => $request->user()->id, 'vendor_id' => $item->vendor_id, 'rating' => $input['rating'], 'comment' => $input['comment'], 'status' => 'pending', 'revision' => ($existing?->revision ?? -1) + 1, 'created_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(['message' => 'Thank you. Your verified-purchase review is awaiting moderation.']);
    }

    public function reviews(Request $request, int $id): JsonResponse
    {
        $rows = DB::table('product_reviews')->join('users', 'users.id', '=', 'product_reviews.user_id')->where('product_id', $id)->where('status', 'published')->select('product_reviews.id', 'rating', 'comment', 'product_reviews.created_at', 'users.name')->latest('product_reviews.id')->paginate(10);

        return response()->json(['data' => collect($rows->items())->map(fn ($row) => ['id' => $row->id, 'rating' => $row->rating, 'comment' => $row->comment, 'reviewer' => mb_substr($row->name, 0, 1).'…', 'created_at' => $row->created_at, 'verified_purchase' => true]), 'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage()]);
    }

    public function reorder(Request $request, string $reference): JsonResponse
    {
        $input = $request->validate(['locality_id' => 'required|integer']);
        $address = ServiceLocations::address($input);
        $order = DB::table('orders')->where('reference', $reference)->where('user_id', $request->user()->id)->where('is_sample', false)->first();
        abort_unless($order, 404);
        $sections = DB::table('categories')->pluck('shopping_section', 'name');
        $items = [];
        $unavailable = [];
        foreach (DB::table('order_items')->where('order_id', $order->id)->get() as $line) {
            try {
                $product = Marketplace::publicProducts($address['pin'], $line->vendor_id)->where('products.id', $line->product_id)->first();
                if (! $product) {
                    throw ValidationException::withMessages(['items' => 'Unavailable']);
                }
                $offer = Marketplace::selectOffer($line->product_id, $address['pin'], false, $line->quantity, $line->vendor_id);
                $ids = array_column(json_decode($line->selected_options ?? '[]', true), 'id');
                $options = MenuChoices::resolve($offer, $ids);
                $oldChoices = json_decode($line->selected_options ?? '[]', true);
                foreach ($oldChoices as $oldChoice) {
                    $current = collect($options['choices'])->firstWhere('id', $oldChoice['id']);
                    if (! $current || $current['name'] !== $oldChoice['name'] || $current['group'] !== $oldChoice['group']) {
                        throw ValidationException::withMessages(['items' => 'The selected menu choices changed.']);
                    }
                }
                $product->menu_offer = $offer;
                $present = Marketplace::present($product);
                $present['shopping_section'] = $sections[$product->category] ?? 'groceries';
                $present['price_paise'] += (int) $options['extra'];
                $present['selected_vendor_id'] = $line->vendor_id;
                $present['selected_option_ids'] = $ids;
                $present['selected_option_names'] = array_column($options['choices'], 'name');
                $present['menu_revision'] = $offer->menu_revision;
                $present['option_groups'] = json_decode($offer->option_groups ?? '[]', true);
                $items[] = ['product' => $present, 'quantity' => $line->quantity, 'vendor_id' => $line->vendor_id, 'options' => $ids];
            } catch (ValidationException $e) {
                $unavailable[] = ['name' => $line->product_name, 'reason' => 'Unavailable, insufficient stock, or menu choices have changed.'];
            }
        }

        return response()->json(['data' => ['items' => $items, 'unavailable' => $unavailable, 'message' => 'Review current prices and availability before checkout.']]);
    }
}
