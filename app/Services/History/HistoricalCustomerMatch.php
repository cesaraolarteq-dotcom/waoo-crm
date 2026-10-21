<?php
namespace App\Services\History;

use App\Exceptions\RecordWriteRejected;
use App\Filament\Support\ContactLinks;
use App\Models\{Customer, ExternalCustomerLink};
use App\Services\Finance\MutationKey;
use Illuminate\Support\Collection;

final class HistoricalCustomerMatch
{
    public static function customers(): Collection
    {
        $rows = Customer::orderBy('id')->limit(10001)->lockForUpdate()->get();
        RecordWriteRejected::unless($rows->count() <= 10000, 409, 'La cantidad de clientas requiere una revisión de importación por volumen.');
        return $rows;
    }
    public static function read(#[\SensitiveParameter] array $source, ?Collection $customers = null): array
    {
        $customers ??= self::customers();
        $link = $source['contact_id'] !== '' ? ExternalCustomerLink::where('source_system', 'honeybook')
            ->where('contact_key', hash('sha256', $source['contact_id']))->lockForUpdate()->first() : null;
        if ($link) HistoricalIntegrity::contact($link);
        $corrected = HistoricalCustomerBinding::project($source['project_id'] ?? '');
        if ($corrected !== null) {
            $customer = $customers->firstWhere('id', $corrected);
            RecordWriteRejected::unless($customer !== null, 409, 'La clienta corregida requiere revisión.');
            $candidates = [['id'=>$customer->id,'version'=>$customer->version,'name'=>$customer->name,'email'=>$customer->email,'phone'=>$customer->phone]];
            return ['state'=>'linked','tier'=>'project_correction','candidate_count'=>1,'candidates'=>$candidates,
                'linked_customer_id'=>$customer->id,'expected_match_sha256'=>MutationKey::hash(['source'=>MutationKey::hash($source),'candidates'=>$candidates,'correction_project'=>$source['project_id']])];
        }
        $email = self::email($source['client_email']); $phone = ContactLinks::digits($source['client_phone']);
        $emailIds = $customers->filter(fn ($c) => $email !== null && self::email($c->email) === $email)->pluck('id')->all();
        $phoneIds = $customers->filter(fn ($c) => $phone !== null && ContactLinks::digits($c->phone) === $phone)->pluck('id')->all();
        $contactIds = $link ? [$link->customer_id] : [];
        $primary = $contactIds ?: ($emailIds ?: $phoneIds);
        $ids = array_values(array_unique([...$contactIds, ...$emailIds, ...$phoneIds])); sort($ids);
        $tier = $contactIds ? 'contact' : ($emailIds ? 'email' : ($phoneIds ? 'phone' : 'none'));
        $conflict = count($ids) > count(array_unique($primary));
        $state = !$ids ? 'none' : ($conflict ? 'conflicting' : (count($primary) > 1 ? 'ambiguous' : ($link ? 'linked' : 'suggested')));
        if ($tier === 'phone' && $customers->whereIn('id', $phoneIds)->contains(fn ($c) => self::name($c->name) === '' || self::name($c->name) !== self::name($source['client_name'] ?? ''))) $state = 'conflicting';
        if (HistoricalCustomerBinding::disputed($source['contact_id'])) $state = 'conflicting';
        $candidates = $customers->whereIn('id', $ids)->map(fn ($c) => ['id' => $c->id, 'version' => $c->version,
            'name' => $c->name, 'email' => $c->email, 'phone' => $c->phone])->values()->all();
        RecordWriteRejected::unless(!$link || count($customers->where('id', $link->customer_id)) === 1, 409, 'La clienta vinculada requiere revisión.');
        return ['state' => $state, 'tier' => $tier, 'candidate_count' => count($ids), 'candidates' => $candidates,
            'expected_match_sha256' => MutationKey::hash(['source' => MutationKey::hash($source), 'candidates' => $candidates,
                'link' => $link ? ['id' => $link->id, 'customer_id' => $link->customer_id, 'version' => $link->version, 'sha256' => $link->payload_sha256] : null]),
            'linked_customer_id' => $link?->customer_id];
    }
    private static function name(?string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim(\Normalizer::normalize($name ?? '', \Normalizer::FORM_C))));
    }
    private static function email(?string $email): ?string
    {
        $email = trim((string)$email);
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_strtolower($email) : null;
    }
    public static function view(array $match): array
    {
        unset($match['linked_customer_id']); return $match;
    }
}
