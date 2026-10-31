<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AppNotificationController extends Controller
{
    private function owner(Request $request): array
    {
        $partner = $request->attributes->get('partner');

        return $partner ? ['partner', $partner->id, $request->attributes->get('partner_token_id')] : ['customer', $request->user()->id, $request->attributes->get('customer_token_id')];
    }

    public function index(Request $request): JsonResponse
    {
        [$type, $id] = $this->owner($request);
        $request->validate(['page' => 'sometimes|integer|min:1']);
        $query = DB::table('app_notifications')->where('owner_type', $type)->where('owner_id', $id);
        $unread = (clone $query)->whereNull('read_at')->count();
        $rows = $query->orderByDesc('id')->paginate(30);

        return response()->json(['data' => $rows->getCollection()->map(function ($row) {
            return ['id' => $row->id, 'title' => $row->title, 'body' => $row->body, 'order_reference' => $row->order_id ? DB::table('orders')->where('id', $row->order_id)->value('reference') : null, 'read' => $row->read_at !== null, 'created_at' => $row->created_at];
        }), 'unread_count' => $unread, 'next_page' => $rows->hasMorePages() ? $rows->currentPage() + 1 : null])->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, int $id): JsonResponse
    {
        [$type, $owner] = $this->owner($request);
        $query = DB::table('app_notifications')->where('id', $id)->where('owner_type', $type)->where('owner_id', $owner);
        abort_unless((clone $query)->exists(), 404);
        $query->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Notification read.']);
    }

    public function register(Request $request): JsonResponse
    {
        [$type, $owner, $session] = $this->owner($request);
        $input = $request->validate(['installation_id' => 'required|uuid', 'token' => 'required|string|min:20|max:4096', 'platform' => 'required|in:android,ios']);
        DB::transaction(function () use ($input, $type, $owner, $session): void {
            $hash = hash('sha256', $input['token']);
            DB::table('app_devices')->where('token_hash', $hash)->where(fn ($query) => $query->where('installation_id', '!=', $input['installation_id'])->orWhere('owner_type', '!=', $type))->delete();
            DB::table('app_devices')->updateOrInsert(['installation_id' => $input['installation_id'], 'owner_type' => $type], ['owner_id' => $owner, 'session_id' => $session, 'platform' => $input['platform'], 'token' => Crypt::encryptString($input['token']), 'token_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
        }, 3);

        return response()->json(['message' => 'Notification device registered.']);
    }

    public function unregister(Request $request, string $installation): JsonResponse
    {
        [$type, $owner] = $this->owner($request);
        DB::table('app_devices')->where('installation_id', $installation)->where('owner_type', $type)->where('owner_id', $owner)->delete();

        return response()->json(['message' => 'Notification device removed.']);
    }
}
