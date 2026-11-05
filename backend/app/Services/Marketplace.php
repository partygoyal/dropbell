<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Marketplace
{
    public static function catalogue(): Builder
    {
        return DB::table('products')->where('products.active', true)->whereIn('products.category', DB::table('categories')->where('active', true)->select('name'))->where(fn ($query) => $query->whereNull('products.sub_category_id')->orWhereIn('products.sub_category_id', DB::table('sub_categories')->where('active', true)->select('id')));
    }

    public static function offers(?string $postalCode = null, ?int $vendorId = null, bool $includeClosed = false): Builder
    {
        $query = DB::table('vendor_products')->join('vendors', 'vendors.id', '=', 'vendor_products.vendor_id')->where('vendors.status', 'approved')->where('vendor_products.available', true)->where('vendor_products.price_paise', '>', 0)->where(fn ($stock) => $stock->whereNull('vendor_products.stock_quantity')->orWhere('vendor_products.stock_quantity', '>', 0));
        if (! $includeClosed) {
            $query->whereNotIn('vendors.id', VendorOperations::closedIds());
        }
        if ($postalCode) {
            $query->whereJsonContains('vendors.service_postal_codes', $postalCode);
        }

        if ($vendorId !== null) {
            $query->where('vendor_products.vendor_id', $vendorId);
        }

        return $query;
    }

    public static function publicProducts(?string $postalCode = null, ?int $vendorId = null): Builder
    {
        $prices = self::offers($postalCode, $vendorId)->select('vendor_products.product_id')->selectRaw('MIN(vendor_products.price_paise) as selling_price, MAX(vendor_products.stock_quantity) as available_stock, SUM(CASE WHEN vendor_products.stock_quantity IS NULL THEN 1 ELSE 0 END) as untracked_offers')->groupBy('vendor_products.product_id');

        return self::catalogue()->leftJoinSub($prices, 'market_prices', fn ($join) => $join->on('products.id', '=', 'market_prices.product_id'))->where(fn ($query) => $query->whereNotNull('market_prices.selling_price')->when($vendorId === null && $postalCode === null, fn ($samples) => $samples->orWhere('products.is_sample', true)))->select('products.*', 'market_prices.available_stock', 'market_prices.untracked_offers')->selectRaw('COALESCE(market_prices.selling_price, products.price_paise) as selling_price, CASE WHEN market_prices.selling_price IS NULL THEN 1 ELSE 0 END as sample_preview');
    }

    public static function publicVendors(?string $postalCode = null): Builder
    {
        return self::offers($postalCode, null, true)
            ->whereIn('vendor_products.product_id', self::catalogue()->select('products.id'))
            ->select('vendors.id', 'vendors.business_name as name', 'vendors.city')
            ->selectRaw('COUNT(DISTINCT vendor_products.product_id) as product_count')
            ->groupBy('vendors.id', 'vendors.business_name', 'vendors.city')
            ->orderBy('vendors.business_name')->orderBy('vendors.id');
    }

    public static function enrich(Collection $products, ?string $postalCode = null, ?int $vendorId = null): Collection
    {
        $offers = self::offers($postalCode, $vendorId)->whereIn('vendor_products.product_id', $products->pluck('id'))->orderBy('vendor_products.price_paise')->orderBy('vendor_products.id')->get(['vendor_products.*'])->unique('product_id')->keyBy('product_id');
        $ratings = DB::table('product_reviews')->whereIn('product_id', $products->pluck('id'))->where('status', 'published')->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))->select('product_id')->selectRaw('AVG(rating) as average, COUNT(*) as total')->groupBy('product_id')->get()->keyBy('product_id');

        return $products->map(function ($product) use ($offers, $ratings) {
            $product->menu_offer = $offers->get($product->id);
            $product->rating_summary = $ratings->get($product->id);

            return $product;
        });
    }

    public static function present(object $product): array
    {
        $sample = (bool) ($product->sample_preview ?? false);
        $stock = $sample ? (int) $product->stock_quantity : (($product->untracked_offers ?? 0) > 0 ? null : (int) ($product->available_stock ?? 0));

        return ['menu_revision' => ($product->menu_offer ?? null)?->menu_revision ?? 0, 'option_groups' => json_decode(($product->menu_offer ?? null)?->option_groups ?? '[]', true) ?: [], 'option_vendor_id' => ($product->menu_offer ?? null)?->vendor_id ?? null, 'dietary_label' => ($product->menu_offer ?? null)?->dietary_label ?? 'unspecified', 'allergens' => json_decode(($product->menu_offer ?? null)?->allergens ?? '[]', true) ?: [], 'rating' => isset($product->rating_summary) ? round((float) $product->rating_summary->average, 1) : null, 'review_count' => isset($product->rating_summary) ? (int) $product->rating_summary->total : 0, 'is_sample' => $sample, 'stock_quantity' => $stock, 'id' => $product->id, 'name' => $product->name, 'category' => $product->category, 'sub_category_id' => $product->sub_category_id, 'pack_size' => $product->pack_size, 'price_paise' => $product->selling_price, 'available' => ! $sample, 'max_order_quantity' => $sample ? 0 : min(99, $stock ?? 99), 'description' => $product->description, 'care_instructions' => $product->care_instructions, 'image_url' => $product->image_path ? asset('storage/'.$product->image_path) : null];
    }

    public static function selectOffer(int $productId, string $postalCode, bool $lock = false, int $quantity = 1, ?int $vendorId = null): object
    {
        $query = self::offers($postalCode, $vendorId)->where('vendor_products.product_id', $productId)->whereIn('vendor_products.product_id', self::catalogue()->select('products.id'))->select('vendor_products.*', 'vendors.business_name', 'vendors.pickup_address', 'vendors.city', 'vendors.postal_code')->orderBy('vendor_products.price_paise')->orderBy('vendor_products.id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $query->where(fn ($stock) => $stock->whereNull('vendor_products.stock_quantity')->orWhere('vendor_products.stock_quantity', '>=', $quantity));
        $offer = $query->first();
        if (! $offer) {
            throw ValidationException::withMessages(['items' => 'A product is no longer available from a vendor serving this address. Review your bag or choose another address.']);
        }

        if ($lock) {
            $vendor = DB::table('vendors')->where('id', $offer->vendor_id)->lockForUpdate()->first();
            if (! $vendor || $vendor->status !== 'approved' || VendorOperations::status($vendor) !== 'open') {
                throw ValidationException::withMessages(['items' => 'This shop is busy or closed. Please choose another shop.']);
            }
        }

        return $offer;
    }

    public static function changeStock(int $offerId, int $delta): void
    {
        $offer = DB::table('vendor_products')->where('id', $offerId)->lockForUpdate()->first();
        if (! $offer || $offer->stock_quantity === null) {
            return;
        }
        if ($offer->stock_quantity + $delta < 0) {
            throw ValidationException::withMessages(['items' => 'There is insufficient vendor stock. Refresh your bag.']);
        }
        DB::table('vendor_products')->where('id', $offerId)->update(['stock_quantity' => $offer->stock_quantity + $delta, 'revision' => $offer->revision + 1, 'updated_at' => now()]);
    }

    public static function snapshot(object $offer): array
    {
        return ['vendor_id' => $offer->vendor_id, 'vendor_product_id' => $offer->id, 'vendor_name' => $offer->business_name, 'pickup_address' => json_encode(['street' => $offer->pickup_address, 'city' => $offer->city, 'pin' => $offer->postal_code])];
    }
}
