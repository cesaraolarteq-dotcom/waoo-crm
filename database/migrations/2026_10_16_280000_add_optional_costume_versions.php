<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', fn (Blueprint $t) => $t->json('outfits')->nullable());
        Schema::table('resources', fn (Blueprint $t) => $t->string('outfit', 60)->nullable());
        // The replacement index must support the service foreign key before dropping the old unique.
        Schema::table('event_service_characters', function (Blueprint $t) {
            $t->index(['event_service_id', 'character_id'], 'event_character_lookup');
            $t->string('outfit', 60)->nullable();
            $t->string('note', 200)->nullable();
        });
        Schema::table('event_service_characters', fn (Blueprint $t) => $t->dropUnique(['event_service_id', 'character_id']));
    }

    public function down(): void
    {
        if (DB::table('event_service_characters')->select('event_service_id', 'character_id')->groupBy('event_service_id', 'character_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot restore character-only uniqueness without losing costume history; use a forward migration.');
        }
        Schema::table('event_service_characters', fn (Blueprint $t) => $t->unique(['event_service_id', 'character_id']));
        Schema::table('event_service_characters', function (Blueprint $t) {
            $t->dropIndex('event_character_lookup');
            $t->dropColumn(['outfit', 'note']);
        });
        Schema::table('resources', fn (Blueprint $t) => $t->dropColumn('outfit'));
        Schema::table('characters', fn (Blueprint $t) => $t->dropColumn('outfits'));
    }
};
