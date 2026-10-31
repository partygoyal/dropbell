<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetupDehradunLocations extends Command
{
    protected $signature = 'app:setup-dehradun';

    protected $description = 'Add the five initial Dehradun localities at ₹10/km; keep new localities inactive until admin enters their distances.';

    public function handle(): int
    {
        DB::transaction(function (): void {
            DB::table('service_states')->insertOrIgnore(['name' => 'Uttarakhand', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $state = DB::table('service_states')->where('name', 'Uttarakhand')->value('id');
            DB::table('service_cities')->insertOrIgnore(['state_id' => $state, 'name' => 'Dehradun', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $city = DB::table('service_cities')->where('state_id', $state)->where('name', 'Dehradun')->value('id');
            foreach (['Rajpur Road', 'Dalanwala', 'Dharampur', 'Patel Nagar', 'Race Course'] as $name) {
                DB::table('service_localities')->insertOrIgnore(['city_id' => $city, 'name' => $name, 'postal_code' => '248001', 'pricing_mode' => 'locality_per_km', 'rate_paise_per_km' => 1000, 'distance_meters' => null, 'fee_paise' => 0, 'active' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->info('Five Dehradun localities are saved. New areas use ₹10/km and remain inactive until distances are entered in Admin → Location master. Existing settings and rider assignments are preserved.');

        return self::SUCCESS;
    }
}
