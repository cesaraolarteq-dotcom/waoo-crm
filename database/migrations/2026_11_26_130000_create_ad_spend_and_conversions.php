<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-11-26 (point 5): ad spend per day/campaign and which booked prospects were reported back to the ad platform.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_spend_daily', function (Blueprint $t) {
            $t->id();
            $t->date('day');
            $t->string('platform', 12); // google | meta
            $t->string('campaign_id', 40);
            $t->string('campaign', 200);
            $t->unsignedBigInteger('cost_cents');
            $t->unsignedInteger('clicks')->default(0);
            $t->unsignedInteger('impressions')->default(0);
            $t->timestamps();
            $t->unique(['day', 'platform', 'campaign_id']);
        });
        Schema::create('ad_conversion_uploads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->constrained('leads')->restrictOnDelete();
            $t->string('platform', 12);
            $t->unsignedBigInteger('value_cents');
            $t->timestamp('reported_at');
            $t->timestamps();
            $t->unique(['lead_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_conversion_uploads');
        Schema::dropIfExists('ad_spend_daily');
    }
};
