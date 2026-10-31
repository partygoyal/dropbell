<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\LengthAwarePaginator;

class AdminTables
{
    private const COLUMNS = [
        'vendors' => ['id', 'business_name', 'owner_name', 'email', 'phone', 'city', 'postal_code', 'status', 'archived_at', 'created_at'],
        'vendor-products' => ['product_id', 'name', 'pack_size', 'price_paise', 'available', 'stock_quantity'],
        'customers' => ['id', 'name', 'phone', 'order_count', 'created_at'],
        'customer-orders' => ['id', 'reference', 'status', 'payment_status', 'total_paise', 'created_at'],
        'orders' => ['id', 'reference', 'status', 'payment_status', 'subtotal_paise', 'discount_paise', 'delivery_paise', 'total_paise', 'created_at'],
        'products' => ['id', 'name', 'category', 'pack_size', 'active', 'selling_price', 'available_stock', 'created_at'],
        'partners' => ['id', 'name', 'phone', 'vehicle', 'active', 'is_online', 'kyc_status', 'open_orders'],
        'areas' => ['id', 'name', 'postal_code', 'fee_paise', 'minimum_paise', 'free_above_paise', 'active'],
        'categories' => ['id', 'name', 'description', 'active'],
        'requests' => ['id', 'reference', 'type', 'status', 'amount_paise', 'created_at'],
        'transactions' => ['id', 'order_reference', 'type', 'amount_paise', 'status', 'method', 'reference', 'updated_at'],
        'inventory' => ['id', 'name', 'pack_size', 'stock_quantity', 'low_stock_threshold', 'expires_on', 'active'],
        'movements' => ['id', 'type', 'quantity_delta', 'stock_before', 'stock_after', 'actor_name', 'note', 'order_reference', 'created_at'],
        'support' => ['id', 'subject', 'status', 'order_id', 'created_at', 'updated_at'],
        'report-vendors' => ['id', 'business_name', 'city', 'postal_code', 'status', 'archived_at', 'created_at'],
        'report-orders' => ['id', 'reference', 'status', 'payment_status', 'total_paise', 'created_at'],
    ];

    public static function paginate(Builder $query, string $key): LengthAwarePaginator
    {
        abort_unless(request()->user()?->is_admin, 403);
        $input = request()->validate(['per_page' => 'nullable|integer|in:10,20,50,100,200', 'export_table' => 'nullable|string|max:40', 'export' => 'nullable|in:all']);
        if (($input['export'] ?? null) === 'all' && ($input['export_table'] ?? null) === $key) {
            $columns = self::COLUMNS[$key];
            throw new HttpResponseException(response()->streamDownload(function () use ($query, $columns): void {
                $stream = fopen('php://output', 'w');
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, array_map(fn ($column) => ucwords(str_replace('_', ' ', str_replace(['_paise', 'selling_price'], [' (INR)', 'selling price (INR)'], $column))), $columns), escape: '');
                foreach ($query->cursor() as $record) {
                    $values = array_map(function ($column) use ($record): string {
                        $value = $record->{$column} ?? '';
                        if ((str_ends_with($column, '_paise') || $column === 'selling_price') && $value !== '') {
                            $value = number_format($value / 100, 2, '.', '');
                        }
                        $value = (string) $value;

                        return preg_match('/^\s*[=+@-]/u', $value) ? "'".$value : $value;
                    }, $columns);
                    fputcsv($stream, $values, escape: '');
                }
                fclose($stream);
            }, $key.'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']));
        }

        return $query->paginate($input['per_page'] ?? 20)->withQueryString();
    }
}
