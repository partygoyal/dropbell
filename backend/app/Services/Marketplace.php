<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Marketplace
{
    public static function catalogue(): Builder
    {
        return DB::table('products')->where('products.active', true)->whereIn('products.category', DB::table('categories')->where('active', true)->select('name'))->where(fn ($query) => $query->whereNull('products.sub_category_id')->orWhereIn('products.sub_category_id', DB::table('sub_categories')->where('active', true)->select('id')));
    }

    public static function offers(?string $postalCode = null): Builder
    {
        $query = DB::table('vendor_products')->join('vendors', 'vendors.id', '=', 'vendor_products.vendor_id')->where('vendors.status', 'approved')->where('vendor_products.available', true)->where('vendor_products.price_paise', '>', 0)->where(fn ($stock) => $stock->whereNull('vendor_products.stock_quantity')->orWhere('vendor_products.stock_quantity', '>', 0));
        if ($postalCode) {
            $query->whereJsonContains('vendors.service_postal_codes', $postalCode);
        }

        return $query;
    }

    public static function publicProducts(?string $postalCode = null): Builder
    {
        $prices = self::offers($postalCode)->select('vendor_products.product_id')->selectRaw('MIN(vendor_products.price_paise) as selling_price, MAX(vendor_products.stock_quantity) as available_stock, SUM(CASE WHEN vendor_products.stock_quantity IS NULL THEN 1 ELSE 0 END) as untracked_offers')->groupBy('vendor_products.product_id');

        return self::catalogue()->leftJoinSub($prices, 'market_prices', fn ($join) => $join->on('products.id', '=', 'market_prices.product_id'))->where(fn ($query) => $query->whereNotNull('market_prices.selling_price')->orWhere('products.is_sample', true))->select('products.*', 'market_prices.available_stock', 'market_prices.untracked_offers')->selectRaw('COALESCE(market_prices.selling_price, products.price_paise) as selling_price, CASE WHEN market_prices.selling_price IS NULL THEN 1 ELSE 0 END as sample_preview');
    }

    public static function present(object $product): array
    {
        $sample = (bool) ($product->sample_preview ?? false);
        $stock = $sample ? (int) $product->stock_quantity : (($product->untracked_offers ?? 0) > 0 ? null : (int) ($product->available_stock ?? 0));

        return ['is_sample' => $sample, 'stock_quantity' => $stock, 'id' => $product->id, 'name' => $product->name, 'category' => $product->category, 'sub_category_id' => $product->sub_category_id, 'pack_size' => $product->pack_size, 'price_paise' => $product->selling_price, 'available' => ! $sample, 'max_order_quantity' => $sample ? 0 : min(99, $stock ?? 99), 'description' => $product->description, 'care_instructions' => $product->care_instructions, 'image_url' => $product->image_path ? asset('storage/'.$product->image_path) : null];
    }

    public static function selectOffer(int $productId, string $postalCode, bool $lock = false, int $quantity = 1): object
    {
        $query = self::offers($postalCode)->where('vendor_products.product_id', $productId)->whereIn('vendor_products.product_id', self::catalogue()->select('products.id'))->select('vendor_products.*', 'vendors.business_name', 'vendors.pickup_address', 'vendors.city', 'vendors.postal_code')->orderBy('vendor_products.price_paise')->orderBy('vendor_products.id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $query->where(fn ($stock) => $stock->whereNull('vendor_products.stock_quantity')->orWhere('vendor_products.stock_quantity', '>=', $quantity));
        $offer = $query->first();
        if (! $offer) {
            throw ValidationException::withMessages(['items' => 'A product is no longer available from a vendor serving this address. Review your bag or choose another address.']);
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
