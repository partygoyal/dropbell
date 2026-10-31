<?php

namespace App\Console\Commands;

use App\Services\ServiceLocations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PartnerReadiness extends Command
{
    protected $signature = 'app:partner-readiness';

    protected $description = 'Check partner sign-in, approved rider coverage and push setup without printing secrets.';

    public function handle(): int
    {
        if (! Schema::hasColumn('delivery_partners', 'password')) {
            $this->error('Partner password migration is pending. Run php artisan migrate.');

            return self::FAILURE;
        }
        $approved = DB::table('delivery_partners')->where('active', true)->whereExists(fn ($query) => $query->selectRaw('1')->from('delivery_partner_kycs')->whereColumn('delivery_partner_kycs.delivery_partner_id', 'delivery_partners.id')->where('status', 'approved'));
        $configured = (clone $approved)->whereNotNull('password');
        $this->line('Active, KYC-approved riders: '.(clone $approved)->count());
        $this->line('Approved riders with app passwords: '.(clone $configured)->count());
        $areas = ServiceLocations::query()->get();
        $this->line('Active delivery areas: '.$areas->count());
        $missing = 0;
        foreach ($areas as $area) {
            $count = (clone $configured)->whereIn('delivery_partners.id', DB::table('service_locality_partner')->where('locality_id', $area->id)->select('delivery_partner_id'))->count();
            $this->line($area->city.' / '.$area->name.': '.$count.' configured riders');
            if ($count === 0) {
                $missing++;
            }
        }
        $path = config('services.firebase.credentials');
        $push = $path && is_readable($path);
        $this->line('Push server credentials: '.($push ? 'present; verify a real-device delivery' : 'missing; foreground refresh and the notification inbox remain available'));
        $this->line('Android release signing and real-device tests must be verified in the mobile build environment.');
        if ((clone $configured)->count() === 0 || $areas->isEmpty() || $missing > 0 || ! $push) {
            $this->warn('Launch setup is incomplete. Set rider passwords, approve KYC, assign service areas and configure push before release.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
