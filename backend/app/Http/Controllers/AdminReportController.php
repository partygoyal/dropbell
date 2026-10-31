<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminReportController extends Controller
{
    private function query(Request $request): Builder
    {
        $input = $request->validate(['type' => 'nullable|in:vendors,orders', 'from' => 'nullable|date_format:Y-m-d', 'to' => ($request->filled('from') ? 'nullable|date_format:Y-m-d|after_or_equal:from' : 'nullable|date_format:Y-m-d'), 'q' => 'nullable|string|max:120']);
        if (($input['type'] ?? 'vendors') === 'orders') {
            $query = DB::table('orders')->select('id', 'reference', 'status', 'payment_status', 'total_paise', 'created_at');
            if (! empty($input['q'])) {
                $query->where('reference', 'like', '%'.$input['q'].'%');
            }
        } else {
            $query = DB::table('vendors')->select('id', 'business_name', 'city', 'postal_code', 'status', 'archived_at', 'created_at');
            if (! empty($input['q'])) {
                $query->where(fn ($search) => $search->where('business_name', 'like', '%'.$input['q'].'%')->orWhere('city', 'like', '%'.$input['q'].'%'));
            }
        }
        if (! empty($input['from'])) {
            $query->whereDate('created_at', '>=', $input['from']);
        }
        if (! empty($input['to'])) {
            $query->whereDate('created_at', '<=', $input['to']);
        }

        return $query;
    }

    public function index(Request $request): View
    {
        $query = $this->query($request);

        return view('admin.reports', ['type' => $request->input('type', 'vendors'), 'rows' => $query->orderByDesc('id')->adminPaginate('report-'.($request->input('type') ?: 'vendors'))->withQueryString()]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->query($request);
        $orders = $request->input('type', 'vendors') === 'orders';

        return response()->streamDownload(function () use ($query, $orders): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $orders ? ['Order ID', 'Reference', 'Status', 'Payment status', 'Total (INR)', 'Placed at'] : ['Vendor ID', 'Business', 'City', 'Postal code', 'Status', 'Deleted at', 'Registered at'], escape: '');
            foreach ($query->orderBy('id')->cursor() as $row) {
                $values = $orders ? [$row->id, $row->reference, $row->status, $row->payment_status, number_format($row->total_paise / 100, 2, '.', ''), $row->created_at] : [$row->id, $row->business_name, $row->city, $row->postal_code, $row->status, $row->archived_at, $row->created_at];
                $values = array_map(function ($value): string {
                    $value = (string) $value;

                    return preg_match('/^[\s]*[=+@\-]/u', $value) ? "'".$value : $value;
                }, $values);
                fputcsv($stream, $values, escape: '');
            }
            fclose($stream);
        }, ($orders ? 'orders' : 'vendors').'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
