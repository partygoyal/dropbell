<?php

namespace App\Console\Commands;

use App\Services\Marketplace;
use App\Services\ServiceLocations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerReadiness extends Command
{
    protected $signature = 'app:customer-readiness';

    protected $description = 'Report customer checkout, support, email and push setup without printing secrets.';

    public function handle(): int
    {
        $areas = ServiceLocations::query()->get();
        $this->info('Payment method: Cash on Delivery');
        $this->line('Active, configured localities: '.$areas->count());
        foreach ($areas as $area) {
            $offers = Marketplace::publicProducts($area->postal_code)->whereNotNull('market_prices.selling_price')->count();
            $riders = DB::table('service_locality_partner')->join('delivery_partners', 'delivery_partners.id', '=', 'service_locality_partner.delivery_partner_id')->where('locality_id', $area->id)->where('delivery_partners.active', true)->whereExists(fn ($q) => $q->selectRaw('1')->from('delivery_partner_kycs')->whereColumn('delivery_partner_kycs.delivery_partner_id', 'delivery_partners.id')->where('status', 'approved'))->count();
            $this->line($area->state.' / '.$area->city.' / '.$area->name.': '.$offers.' offered products, '.$riders.' approved assigned riders');
        }
        $pending = DB::table('service_localities')->where('pricing_mode', 'locality_per_km')->whereNull('distance_meters')->count();
        $this->line('Localities awaiting admin distance entry: '.$pending);
        $this->line('Support database: '.(Schema::hasTable('support_tickets') ? 'ready' : 'migration required'));
        $this->line('Password recovery email: '.(in_array(config('mail.default'), ['log', 'array'], true) ? 'local testing only; configure a delivery mailer for production' : 'delivery mailer configured; send a real test before release'));
        $path = config('services.firebase.credentials');
        $this->line('Push notifications: '.($path && is_readable($path) ? 'server credentials present; mobile registration and real device test still required' : 'Firebase server credentials missing'));
        $this->line('Customer website URL: '.(str_starts_with((string) config('customer.web_url'), 'https://') ? 'HTTPS configured' : 'local HTTP; set CUSTOMER_WEB_URL to your HTTPS website before release'));

        return self::SUCCESS;
    }
}
