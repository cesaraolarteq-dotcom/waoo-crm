<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('staff');
            $t->boolean('active')->default(true);
            $t->text('two_factor_secret')->nullable();
            $t->text('two_factor_recovery_codes')->nullable();
            $t->timestamp('two_factor_confirmed_at')->nullable();
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email')->nullable();
            $t->string('phone', 40)->nullable(); $t->string('locale', 2)->default('es'); $t->timestamps();
        });
        Schema::create('characters', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->boolean('active')->default(true); $t->timestamps();
        });
        Schema::create('resources', function (Blueprint $t) {
            $t->id(); $t->string('code')->unique(); $t->string('name');
            $t->string('kind', 20)->index(); $t->string('status', 20)->default('active');
            $t->foreignId('character_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('location')->nullable(); $t->json('skills')->nullable();
            $t->string('equipment_kind')->nullable(); $t->unsignedSmallInteger('seats')->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
        });
        Schema::create('costume_compatibilities', function (Blueprint $t) {
            $t->foreignId('staff_id')->constrained('resources')->restrictOnDelete();
            $t->foreignId('costume_id')->constrained('resources')->restrictOnDelete();
            $t->primary(['staff_id', 'costume_id']);
        });
        Schema::create('service_variants', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('status', 20)->default('draft');
            $t->unsignedInteger('duration_minutes'); $t->json('requirements');
            $t->unsignedInteger('version')->default(1); $t->timestamps();
        });
        Schema::create('price_versions', function (Blueprint $t) {
            $t->id(); $t->foreignId('service_variant_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('amount_cents'); $t->string('currency', 3)->default('USD');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
        });
        Schema::create('events', function (Blueprint $t) {
            $t->id(); $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->string('title'); $t->dateTime('starts_at'); $t->dateTime('ends_at');
            $t->string('timezone')->default('America/Chicago'); $t->string('address')->nullable();
            $t->unsignedBigInteger('travel_fee_cents')->nullable();
            $t->unsignedSmallInteger('setup_minutes')->nullable();
            $t->unsignedSmallInteger('breakdown_minutes')->nullable();
            $t->string('status', 30)->default('draft')->index();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
        });
        Schema::create('event_services', function (Blueprint $t) {
            $t->id(); $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->foreignId('service_variant_id')->constrained()->restrictOnDelete();
            $t->foreignId('price_version_id')->constrained()->restrictOnDelete();
            $t->boolean('operational_ready')->default(false); $t->json('requirements_snapshot'); $t->unsignedInteger('duration_minutes'); $t->timestamps();
        });
        Schema::create('booking_evidence', function (Blueprint $t) {
            $t->id(); $t->foreignId('event_id')->unique()->constrained()->restrictOnDelete();
            $t->string('method', 30); $t->string('reference')->unique();
            $t->unsignedBigInteger('amount_cents'); $t->dateTime('received_at');
            $t->foreignId('verified_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
        });
        Schema::create('resource_reservations', function (Blueprint $t) {
            $t->id(); $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->foreignId('resource_id')->constrained()->restrictOnDelete();
            $t->dateTime('starts_at'); $t->dateTime('ends_at');
            $t->index(['resource_id', 'starts_at', 'ends_at']);
            $t->unique(['event_id', 'resource_id']); $t->timestamps();
        });
        Schema::create('resource_blocks', function (Blueprint $t) {
            $t->id(); $t->foreignId('resource_id')->constrained()->restrictOnDelete();
            $t->dateTime('starts_at'); $t->dateTime('ends_at'); $t->string('reason');
            $t->index(['resource_id', 'starts_at', 'ends_at']); $t->timestamps();
        });
        Schema::create('operational_reviews', function (Blueprint $t) {
            $t->id(); $t->foreignId('event_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $t->json('evidence'); $t->timestamps();
        });
        Schema::create('audit_entries', function (Blueprint $t) {
            $t->id(); $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action'); $t->string('subject_type'); $t->unsignedBigInteger('subject_id');
            $t->json('before')->nullable(); $t->json('after')->nullable(); $t->timestamp('created_at');
            $t->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        foreach (['audit_entries', 'operational_reviews', 'resource_blocks', 'resource_reservations', 'booking_evidence', 'event_services', 'events', 'price_versions', 'service_variants', 'costume_compatibilities', 'resources', 'characters', 'customers'] as $table) Schema::dropIfExists($table);
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role','active','two_factor_secret','two_factor_recovery_codes','two_factor_confirmed_at']));
    }
};
