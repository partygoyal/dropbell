<?php

namespace App\Console\Commands;

use Database\Seeders\DehradunDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportDehradunDemo extends Command
{
    protected $signature = 'demo:import-dehradun {--confirm-production : Explicitly allow adding inactive demo preview records to production}';

    protected $description = 'Add Dehradun admin preview records without changing existing accounts or enabling shop access';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('confirm-production')) {
            $this->error('Production import requires --confirm-production and a database backup.');

            return self::FAILURE;
        }
        (new DehradunDemoSeeder)->importPreview();
        $vendors = DB::table('users')->where('email', 'like', 'dehradun.vendor%@demo.invalid')->where('is_vendor', true)->count();
        $customers = DB::table('users')->where('email', 'like', 'dehradun.customer%@demo.invalid')->where('is_vendor', false)->count();
        $partners = DB::table('delivery_partners')->whereBetween('phone', ['9000082001', '9000082005'])->count();
        $localities = DB::table('service_localities')->join('service_cities', 'service_cities.id', '=', 'service_localities.city_id')->where('service_cities.name', 'Dehradun')->where('service_localities.name', 'like', 'Demo · %')->count();
        $this->info("Demo preview records: {$vendors} vendors, {$customers} customers, {$partners} partners, {$localities} localities.");
        $this->info('New shops and delivery coverage are inactive. Local demo passwords are not imported.');

        return self::SUCCESS;
    }
}
