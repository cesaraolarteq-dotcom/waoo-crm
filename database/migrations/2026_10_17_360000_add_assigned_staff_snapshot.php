<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void{Schema::table('booking_evidence',fn(Blueprint $t)=>$t->json('staff_assignment_snapshot')->nullable());}
    public function down():void{Schema::table('booking_evidence',fn(Blueprint $t)=>$t->dropColumn('staff_assignment_snapshot'));}
};
