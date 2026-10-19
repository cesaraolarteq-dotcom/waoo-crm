<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->enum('responsible_system', ['honeybook', 'crm'])->default('crm');
        });
        // Query builder only: no model hooks, private source data or financial changes.
        DB::transaction(function () {
            DB::table('events')->whereIn('id', DB::table('imported_project_links')->select('event_id'))->update(['responsible_system' => 'honeybook']);
            DB::table('events')->select(['id', 'responsible_system'])->orderBy('id')->chunkById(500, function ($events) {
                foreach ($events as $event) {
                    DB::table('audit_entries')->insert(['actor_id' => null, 'action' => 'event.responsibility_classified',
                        'subject_type' => 'events', 'subject_id' => $event->id, 'before' => '{}',
                        'after' => json_encode(['responsible_system' => $event->responsible_system, 'source' => 'migration_390000'], JSON_THROW_ON_ERROR), 'created_at' => now()]);
                }
            });
        });
    }
    public function down(): void { Schema::table('events', fn (Blueprint $table) => $table->dropColumn('responsible_system')); }
};
