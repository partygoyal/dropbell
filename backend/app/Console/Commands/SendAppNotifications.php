<?php

namespace App\Console\Commands;

use App\Services\AppNotifications;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:send-app-notifications {--limit=100}')]
#[Description('Deliver queued mobile push notifications using Firebase HTTP v1')]
class SendAppNotifications extends Command
{
    public function handle(): int
    {
        if (! config('services.firebase.credentials')) {
            $this->warn('Firebase is not configured. In-app notifications remain available.');

            return self::SUCCESS;
        }
        try {
            $sent = AppNotifications::deliver(max(1, min(500, (int) $this->option('limit'))));
            $this->info('Sent '.$sent.' push notifications.');
        } catch (\Throwable $exception) {
            $this->error('Could not initialize push delivery. Check Firebase credentials and connectivity.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
