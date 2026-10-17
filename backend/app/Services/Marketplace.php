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
        $query = DB::table('vendor_products')->join('vendors', 'vendors.id', '=', 'vendor_products.vendor_id')->where('vendors.status', 'approved')->where('vendor_products.available', true)->where('vendor_products.price_paise', '>', 0);
        if ($postalCode) {
            $query->whereJsonContains('vendors.service_postal_codes', $postalCode);
        }

        return $query;
    }

    public static function publicProducts(?string $postalCode = null): Builder
    {
        $prices = self::offers($postalCode)->select('vendor_products.product_id')->selectRaw('MIN(vendor_products.price_paise) as selling_price')->groupBy('vendor_products.product_id');

        return self::catalogue()->joinSub($prices, 'market_prices', fn ($join) => $join->on('products.id', '=', 'market_prices.product_id'))->select('products.*', 'market_prices.selling_price');
    }

    public static function present(object $product): array
    {
        return ['id' => $product->id, 'name' => $product->name, 'category' => $product->category, 'sub_category_id' => $product->sub_category_id, 'pack_size' => $product->pack_size, 'price_paise' => $product->selling_price, 'available' => true, 'max_order_quantity' => 99, 'description' => $product->description, 'care_instructions' => $product->care_instructions, 'image_url' => $product->image_path ? asset('storage/'.$product->image_path) : null];
    }

    public static function selectOffer(int $productId, string $postalCode, bool $lock = false): object
    {
        $query = self::offers($postalCode)->where('vendor_products.product_id', $productId)->whereIn('vendor_products.product_id', self::catalogue()->select('products.id'))->select('vendor_products.*', 'vendors.business_name', 'vendors.pickup_address', 'vendors.city', 'vendors.postal_code')->orderBy('vendor_products.price_paise')->orderBy('vendor_products.id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $offer = $query->first();
        if (! $offer) {
            throw ValidationException::withMessages(['items' => 'A product is no longer available from a vendor serving this address. Review your bag or choose another address.']);
        }

        return $offer;
    }

    public static function snapshot(object $offer): array
    {
        return ['vendor_id' => $offer->vendor_id, 'vendor_product_id' => $offer->id, 'vendor_name' => $offer->business_name, 'pickup_address' => json_encode(['street' => $offer->pickup_address, 'city' => $offer->city, 'pin' => $offer->postal_code])];
    }
}
