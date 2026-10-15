<?php
namespace Database\Seeders;

use App\Models\{Character, Customer, Resource, ServiceVariant};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment(['local','testing'])) throw new \RuntimeException('Demo fixtures are restricted to local/testing databases.');
        DB::transaction(function () {
            Customer::firstOrCreate(['email'=>'demo@example.test'],['name'=>'DEMO — fictitious client','locale'=>'es']);
            $character=Character::firstOrCreate(['name'=>'DEMO — fictitious character'],['active'=>true]);
            for($i=1;$i<=4;$i++) Resource::firstOrCreate(['code'=>'DEMO-SPEAKER-'.$i],['name'=>'DEMO speaker '.$i,'kind'=>'equipment','status'=>'active','equipment_kind'=>'speaker','skills'=>[]]);
            for($i=1;$i<=2;$i++) Resource::firstOrCreate(['code'=>'DEMO-COSTUME-'.$i],['name'=>'DEMO costume '.$i,'kind'=>'costume','status'=>'active','character_id'=>$character->id,'skills'=>[]]);
            Resource::firstOrCreate(['code'=>'DEMO-STAFF-1'],['name'=>'DEMO — fictitious performer','kind'=>'staff','status'=>'active','skills'=>['performer','driver']]);
            ServiceVariant::firstOrCreate(['name'=>'DEMO — recipe awaiting review'],['status'=>'draft','duration_minutes'=>60,'requirements'=>[]]);
            // No prices, bookings, payment evidence, accounts or operational assumptions are seeded.
        });
    }
}
