<?php
namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class Audit
{
    public static function record(string $action, Model $subject, array $before = [], array $after = []): void
    {
        self::insert(auth()->id(), $action, $subject, $before, $after);
    }

    public static function recordFor(User $actor, string $action, Model $subject, array $before = [], array $after = []): void
    {
        self::insert($actor->getKey(), $action, $subject, $before, $after);
    }

    private static function insert(?int $actorId, string $action, Model $subject, array $before, array $after): void
    {
        DB::table('audit_entries')->insert([
            'actor_id' => $actorId, 'action' => $action,
            'subject_type' => $subject->getTable(), 'subject_id' => $subject->getKey(),
            'before' => json_encode($before, JSON_THROW_ON_ERROR),
            'after' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
