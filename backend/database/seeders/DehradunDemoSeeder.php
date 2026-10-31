<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DehradunDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Dehradun demo data can only be added locally.');
        }
        DB::transaction(function (): void {
            $password = Hash::make('DemoLocal@1234');
            DB::table('service_states')->insertOrIgnore(['name' => 'Uttarakhand', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $stateId = DB::table('service_states')->where('name', 'Uttarakhand')->value('id');
            DB::table('service_cities')->insertOrIgnore(['state_id' => $stateId, 'name' => 'Dehradun', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $cityId = DB::table('service_cities')->where('state_id', $stateId)->where('name', 'Dehradun')->value('id');
            $localityIds = [];
            foreach (['Rajpur Road', 'Dalanwala', 'Patel Nagar', 'Clement Town', 'Prem Nagar', 'Sahastradhara Road'] as $index => $name) {
                $demoName = 'Demo · '.$name;
                DB::table('service_localities')->insertOrIgnore(['city_id' => $cityId, 'name' => $demoName, 'postal_code' => '248001', 'pricing_mode' => 'flat', 'fee_paise' => 2500 + $index * 500, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                $localityIds[] = DB::table('service_localities')->where('city_id', $cityId)->where('name', $demoName)->value('id');
            }
            $partnerIds = [];
            foreach (['Amit Rawat', 'Vikram Negi', 'Deepak Rana', 'Sandeep Bisht', 'Mohit Singh'] as $index => $name) {
                $phone = (string) (9000082001 + $index);
                $name = 'Demo · '.$name;
                DB::table('delivery_partners')->insertOrIgnore(['name' => $name, 'phone' => $phone, 'vehicle' => 'Demo scooter', 'password' => $password, 'active' => true, 'is_online' => false, 'created_at' => now(), 'updated_at' => now()]);
                $partner = DB::table('delivery_partners')->where('phone', $phone)->first();
                if (! $partner || $partner->name !== $name) {
                    throw new RuntimeException('A demo phone is already used by another partner. No demo data was added.');
                }
                $partnerIds[] = $partner->id;
            }
            foreach ($localityIds as $index => $localityId) {
                foreach ([$partnerIds[$index % 5], $partnerIds[($index + 1) % 5]] as $partnerId) {
                    DB::table('service_locality_partner')->insertOrIgnore(['locality_id' => $localityId, 'delivery_partner_id' => $partnerId]);
                }
            }
            foreach (['Doon Fresh Mart', 'Valley Daily Essentials', 'Hillview Grocery', 'Dehradun Family Store'] as $index => $business) {
                $userId = $this->user('vendor'.($index + 1), 'Demo Vendor '.($index + 1), (string) (9000081001 + $index), true, $password);
                DB::table('vendors')->insertOrIgnore(['user_id' => $userId, 'business_name' => 'Demo · '.$business, 'pickup_address' => 'Demo pickup shop '.($index + 1), 'city' => 'Dehradun', 'postal_code' => '248001', 'service_postal_codes' => json_encode(['248001']), 'status' => 'approved', 'review_note' => 'Local demo shop. Postal codes, prices and stock are illustrative.', 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
            $names = ['Aarav Sharma', 'Ananya Rawat', 'Rohan Negi', 'Priya Bisht', 'Aditya Rana', 'Neha Joshi', 'Karan Singh', 'Sneha Nautiyal', 'Rahul Uniyal', 'Pooja Chauhan', 'Ishaan Gupta', 'Divya Thapliyal', 'Manav Sethi', 'Kavya Pant', 'Nikhil Verma', 'Riya Mehra', 'Sahil Bhandari', 'Meera Shah', 'Varun Dobhal', 'Tanya Kapoor'];
            foreach ($names as $index => $name) {
                $phone = (string) (9000080001 + $index);
                $userId = $this->user('customer'.($index + 1), 'Demo · '.$name, $phone, false, $password);
                $locality = DB::table('service_localities')->find($localityIds[$index % 6]);
                if (DB::table('addresses')->where('user_id', $userId)->where('label', 'Demo Home')->exists()) {
                    continue;
                }
                DB::table('addresses')->insertOrIgnore(['user_id' => $userId, 'label' => 'Demo Home', 'name' => 'Demo · '.$name, 'phone' => $phone, 'street' => 'Demo residence '.($index + 1).', '.$locality->name, 'city' => 'Dehradun', 'postal_code' => '248001', 'locality_id' => $locality->id, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    private function user(string $key, string $name, string $phone, bool $vendor, string $password): int
    {
        $email = 'dehradun.'.$key.'@demo.invalid';
        $existing = DB::table('users')->where('email', $email)->first();
        if ($existing) {
            if ($existing->is_admin || (bool) $existing->is_vendor !== $vendor) {
                throw new RuntimeException('Demo email belongs to an incompatible account.');
            }

            return $existing->id;
        }

        return DB::table('users')->insertGetId(['name' => $name, 'email' => $email, 'phone' => $phone, 'password' => $password, 'is_admin' => false, 'is_vendor' => $vendor, 'is_sample_guest' => false, 'created_at' => now(), 'updated_at' => now()]);
    }
}
