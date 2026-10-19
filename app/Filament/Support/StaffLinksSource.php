<?php

namespace App\Filament\Support;

use App\Models\User;

// STAFF-01 v2 owner screen data (docs/STAFF-01-API.md): one durable personal link per staff member, copy / rotate /
// turn off. Calls Codex's services when they exist; until then local/testing shows a labelled sample with the same
// keys and every action is refused (nothing is written, no real link exists). Never returns a token in listings.
final class StaffLinksSource
{
    public const READ = 'App\\Services\\Staff\\ReadStaffLinks';
    public const ISSUE = 'App\\Services\\Staff\\IssueStaffGrant';
    public const REVEAL = 'App\\Services\\Staff\\RevealStaffGrantLink';
    public const ROTATE = 'App\\Services\\Staff\\RotateStaffGrant';
    public const REVOKE = 'App\\Services\\Staff\\RevokeStaffGrant';
    public const IDENTITY = 'App\\Services\\Staff\\SaveStaffIdentity';

    public static function live(): bool
    {
        foreach ([self::READ, self::ISSUE, self::REVEAL, self::ROTATE, self::REVOKE, self::IDENTITY] as $c) if (! class_exists($c)) return false;

        return config('waoo.sandbox_staff') === true;
    }

    public static function sample(): bool
    {
        return ! self::live() && app()->environment(['local', 'testing']);
    }

    /** @return array{data: array, current_page: int, last_page: int, sample: bool} */
    public static function links(User $owner, int $page = 1): array
    {
        if (self::live()) return [...app(self::READ)->execute($owner, ['page' => max(1, $page)]), 'sample' => false];
        $row = fn ($id, $name, $locale, $grant) => ['staff_id' => $id, 'resource_id' => 100 + $id, 'display_name' => $name, 'locale' => $locale, 'active' => true, 'version' => 1, 'grant' => $grant];

        return ['data' => [
            $row(1, 'Lucía (ejemplo)', 'es', ['id' => 11, 'version' => 1, 'last_opened_at' => now()->subHours(3)->toIso8601String()]),
            $row(2, 'Carlos (ejemplo)', 'en', ['id' => 12, 'version' => 2, 'last_opened_at' => null]),
            $row(3, 'Andrea (ejemplo)', 'es', null),
        ], 'current_page' => 1, 'last_page' => 1, 'sample' => true];
    }

    /** issue|reveal|rotate|revoke → envelope with `link` for issue/reveal/rotate. Sample mode refuses. */
    public static function act(string $what, User $owner, int $id, int $expectedVersion, ?string $key): array
    {
        if (! self::live()) throw new \App\Exceptions\RecordWriteRejected(409, 'Vista previa: los enlaces del staff se activan cuando Codex termine su parte.');
        $input = ['expected_version' => $expectedVersion];

        return match ($what) {
            'issue' => app(self::ISSUE)->execute($owner, $id, $input, (string) $key),
            'reveal' => app(self::REVEAL)->execute($owner, $id, $input),
            'rotate' => app(self::ROTATE)->execute($owner, $id, $input, (string) $key),
            'revoke' => app(self::REVOKE)->execute($owner, $id, $input, (string) $key),
        };
    }

    /** Active staff resources that have no portal identity yet (owner sets language/phone first). */
    public static function unconfigured(): array
    {
        if (! self::live()) return [['resource_id' => 104, 'resource_version' => 1, 'name' => 'Sofía (ejemplo)']];

        return \App\Models\Resource::where('kind', 'staff')->where('status', 'active')
            ->whereNotIn('id', \App\Models\StaffIdentity::query()->select('resource_id'))->orderBy('name')->limit(100)->get()
            ->map(fn ($r) => ['resource_id' => $r->id, 'resource_version' => (int) $r->version, 'name' => $r->name])->all();
    }

    /** Resource version for an identity row (SaveStaffIdentity pins both). */
    public static function resourceVersion(int $resourceId): ?int
    {
        return self::live() ? \App\Models\Resource::whereKey($resourceId)->value('version') : 1;
    }

    public static function saveIdentity(User $owner, array $input, string $key): array
    {
        if (! self::live()) throw new \App\Exceptions\RecordWriteRejected(409, 'Vista previa: los enlaces del staff se activan cuando Codex termine su parte.');

        return app(self::IDENTITY)->execute($owner, $input, $key);
    }

    /** Owner-facing message for WhatsApp/SMS. The link never expires on its own; it shows the current week. */
    public static function message(string $lang, string $name, string $url): string
    {
        $first = trim(explode(' ', $name)[0] ?? '');

        return $lang === 'en'
            ? "Hi {$first}! This is your personal Waoo Party link: {$url} . It always shows your parties for the week (arrival time, address, what to bring). Save it on your phone; it stays the same."
            : "¡Hola {$first}! Este es tu enlace personal de Waoo Party: {$url} . Ahí ves siempre tus fiestas de la semana (hora de llegada, dirección y qué llevar). Guárdalo en tu celular; siempre es el mismo.";
    }
}
