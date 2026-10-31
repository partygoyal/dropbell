<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AppNotifications
{
    public static function record(string $type, int $ownerId, object $order, string $event, string $title, string $body): void
    {
        if ($order->is_sample) {
            return;
        }
        $key = hash('sha256', $type.':'.$ownerId.':'.$order->id.':'.$event);
        $created = DB::table('app_notifications')->insertOrIgnore(['owner_type' => $type, 'owner_id' => $ownerId, 'order_id' => $order->id, 'event_key' => $key, 'event_type' => $event, 'title' => $title, 'body' => $body, 'created_at' => now(), 'updated_at' => now()]);
        if (! $created) {
            return;
        }
        $id = DB::table('app_notifications')->where('event_key', $key)->value('id');
        foreach (DB::table('app_devices')->where('owner_type', $type)->where('owner_id', $ownerId)->get() as $device) {
            DB::table('app_notification_deliveries')->insertOrIgnore(['notification_id' => $id, 'device_id' => $device->id, 'token_hash' => $device->token_hash, 'status' => 'pending', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public static function customer(object $order, string $event, string $title, string $body): void
    {
        self::record('customer', $order->user_id, $order, $event, $title, $body);
    }

    public static function assignment(object $order, ?int $previous = null): void
    {
        if ($previous && $previous !== (int) $order->delivery_partner_id) {
            self::record('partner', $previous, $order, 'unassigned:'.$order->revision, 'Delivery reassigned', 'A delivery assignment has changed. Refresh your assigned orders.');
        }
        if ($order->delivery_partner_id) {
            self::record('partner', $order->delivery_partner_id, $order, 'assigned:'.$order->revision, 'New delivery assigned', 'A delivery has been assigned to you. Open the app to review your next stop.');
        }
    }

    public static function status(object $order): void
    {
        $messages = ['received' => ['Order received', 'Your order has been received. We’ll update you as it progresses.'], 'picking' => ['Preparing your order', 'Your items are being collected.'], 'out_for_delivery' => ['Your order is on its way', 'Your delivery partner is heading to your address.'], 'delivered' => ['Order delivered', 'Your order has been delivered. Thank you for shopping with Dropbell.'], 'cancelled' => ['Order cancelled', 'Your order has been cancelled. Open the order for details.']];
        if (isset($messages[$order->status])) {
            self::customer($order, 'status:'.$order->status.':'.$order->revision, ...$messages[$order->status]);
        }
        if ($order->status === 'cancelled' && $order->delivery_partner_id) {
            self::record('partner', $order->delivery_partner_id, $order, 'cancelled:'.$order->revision, 'Delivery cancelled', 'An assigned delivery has been cancelled. Refresh your assigned orders.');
        }
    }

    public static function deviceAuthorized(object $device, object $notification): bool
    {
        if ($device->owner_type !== $notification->owner_type || $device->owner_id !== $notification->owner_id) {
            return false;
        }
        if ($device->owner_type === 'partner') {
            return DB::table('partner_tokens')->where('id', $device->session_id)->where('delivery_partner_id', $device->owner_id)->where('expires_at', '>', now())->whereExists(fn ($query) => $query->selectRaw('1')->from('delivery_partners')->whereColumn('delivery_partners.id', 'partner_tokens.delivery_partner_id')->whereColumn('delivery_partners.phone', 'partner_tokens.phone')->where('active', true))->whereExists(fn ($query) => $query->selectRaw('1')->from('delivery_partner_kycs')->whereColumn('delivery_partner_kycs.delivery_partner_id', 'partner_tokens.delivery_partner_id')->where('status', 'approved'))->exists();
        }

        return DB::table('customer_tokens')->where('id', $device->session_id)->where('user_id', $device->owner_id)->where('expires_at', '>', now())->whereExists(fn ($query) => $query->selectRaw('1')->from('users')->whereColumn('users.id', 'customer_tokens.user_id')->where('is_admin', false)->where('is_vendor', false)->where('is_sample_guest', false))->exists();
    }

    private static function credentials(): array
    {
        $path = config('services.firebase.credentials');
        if (! $path || ! is_readable($path)) {
            throw new RuntimeException('firebase_not_configured');
        }
        $credentials = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (empty($credentials['project_id']) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
            throw new RuntimeException('firebase_credentials_invalid');
        }

        return $credentials;
    }

    private static function accessToken(array $credentials): string
    {
        $key = 'fcm-access:'.hash('sha256', $credentials['client_email'].$credentials['private_key']);

        return Cache::remember($key, 3000, function () use ($credentials): string {
            $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            $header = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $encode(json_encode(['iss' => $credentials['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => now()->timestamp, 'exp' => now()->timestamp + 3600]));
            $unsigned = $header.'.'.$claims;
            if (! openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('firebase_signing_failed');
            }
            $response = Http::timeout(10)->asForm()->post('https://oauth2.googleapis.com/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned.'.'.$encode($signature)]);
            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                throw new RuntimeException('firebase_authentication_failed');
            }

            return $response->json('access_token');
        });
    }

    public static function deliver(int $limit = 100): int
    {
        $credentials = self::credentials();
        $count = 0;
        $ids = DB::table('app_notification_deliveries')->where('status', 'pending')->where('available_at', '<=', now())->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $claimed = DB::table('app_notification_deliveries')->where('id', $id)->where('status', 'pending')->where('available_at', '<=', now())->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))->update(['lease_until' => now()->addMinutes(2), 'updated_at' => now()]);
            if (! $claimed) {
                continue;
            }
            $delivery = DB::table('app_notification_deliveries')->find($id);
            $device = DB::table('app_devices')->find($delivery->device_id);
            $notification = DB::table('app_notifications')->find($delivery->notification_id);
            if (! $device || ! $notification || $device->token_hash !== $delivery->token_hash || ! self::deviceAuthorized($device, $notification) || $notification->created_at < now()->subDay()) {
                DB::table('app_notification_deliveries')->where('id', $id)->update(['status' => 'cancelled', 'lease_until' => null, 'updated_at' => now()]);

                continue;
            }
            try {
                $order = $notification->order_id ? DB::table('orders')->find($notification->order_id) : null;
                if ($notification->read_at !== null || ($device->owner_type === 'partner' && str_starts_with($notification->event_type, 'assigned:') && (int) $order?->delivery_partner_id !== $device->owner_id)) {
                    DB::table('app_notification_deliveries')->where('id', $id)->update(['status' => 'cancelled', 'lease_until' => null, 'updated_at' => now()]);

                    continue;
                }
                $message = ['token' => Crypt::decryptString($device->token), 'notification' => ['title' => $notification->title, 'body' => $notification->body], 'data' => ['notification_id' => (string) $notification->id, 'order_reference' => $order?->reference ?? '', 'audience' => $device->owner_type], 'android' => ['ttl' => '21600s', 'collapse_key' => 'notification-'.$notification->id], 'apns' => ['headers' => ['apns-collapse-id' => 'notification-'.$notification->id]]];
                $response = Http::timeout(15)->withToken(self::accessToken($credentials))->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode($credentials['project_id']).'/messages:send', ['message' => $message]);
                if (! $response->successful()) {
                    $unregistered = collect($response->json('error.details') ?? [])->contains(fn ($detail) => ($detail['errorCode'] ?? null) === 'UNREGISTERED');
                    if ($unregistered) {
                        DB::table('app_notification_deliveries')->where('id', $id)->update(['status' => 'cancelled', 'last_error' => 'token_unregistered', 'lease_until' => null, 'updated_at' => now()]);
                        DB::table('app_devices')->where('id', $device->id)->delete();

                        continue;
                    }
                    throw new RuntimeException('fcm_http_'.$response->status());
                }
                DB::table('app_notification_deliveries')->where('id', $id)->update(['status' => 'sent', 'sent_at' => now(), 'lease_until' => null, 'updated_at' => now()]);
                $count++;
            } catch (\Throwable $exception) {
                $attempts = $delivery->attempts + 1;
                DB::table('app_notification_deliveries')->where('id', $id)->update(['status' => $attempts >= 8 ? 'failed' : 'pending', 'attempts' => $attempts, 'last_error' => $exception instanceof RuntimeException && str_starts_with($exception->getMessage(), 'fcm_http_') ? $exception->getMessage() : 'push_delivery_failed', 'available_at' => now()->addSeconds(min(3600, 30 * (2 ** $attempts))), 'lease_until' => null, 'updated_at' => now()]);
            }
        }

        return $count;
    }
}
