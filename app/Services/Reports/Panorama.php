<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

/**
 * Owner "Panorama" on Reportes (César 2026-10-28): any window (year, month, week or from–to), compared with the same
 * dates one year earlier, plus forward-looking planning lists. Pure projection over plain rows (see ReadPanorama);
 * unknown amounts stay unknown and are counted, never shown as $0.
 *
 * parties:  key, date (Y-m-d), total_cents ?int, zip ?string, city ?string, source ?string, booked_on ?Y-m-d,
 *           name string, balance_cents ?int, balance_due ?Y-m-d, profit ?['revenue_cents','cost_cents']
 * payments: date (Y-m-d), amount_cents int, deposit bool (first payment of its party)
 * leads:    date (Y-m-d created), status (new|contacted|quoted|booked|lost), reason ?string (why it was lost)
 */
final class Panorama
{
    public const MATERIALS_CENTS = 3000;
    public const SOURCES = ['whatsapp' => 'WhatsApp', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'google' => 'Google',
        'website' => 'Página web', 'referral' => 'Recomendación', 'other' => 'Otro', 'unknown' => 'Sin dato'];
    public const SEASONS = ['halloween' => 'Halloween (octubre)', 'verano' => 'Verano (junio–agosto)', 'diciembre' => 'Diciembre', 'resto' => 'Resto del año'];

    /** @return array{0: string, 1: string} inclusive from/to for a window kind around $anchor. */
    public static function window(string $kind, string $anchor, ?string $from = null, ?string $to = null): array
    {
        $a = CarbonImmutable::parse($anchor, 'America/Chicago');

        return match ($kind) {
            'month' => [$a->startOfMonth()->toDateString(), $a->endOfMonth()->toDateString()],
            'week' => [$a->startOfWeek(CarbonImmutable::MONDAY)->toDateString(), $a->endOfWeek(CarbonImmutable::SUNDAY)->toDateString()],
            'custom' => $from && $to ? [min($from, $to), max($from, $to)] : [$a->startOfYear()->toDateString(), $a->endOfYear()->toDateString()],
            default => [$a->startOfYear()->toDateString(), $a->endOfYear()->toDateString()],
        };
    }

    public static function source(?string $raw): string
    {
        return match (strtolower(trim((string) $raw))) {
            'whatsapp' => 'whatsapp', 'instagram' => 'instagram', 'facebook' => 'facebook',
            'google', 'google ads', 'google maps' => 'google', 'website', 'web', 'página web', 'pagina web' => 'website',
            'referral', 'client referral', 'referido', 'recomendación', 'recomendacion' => 'referral', '' => 'unknown', default => 'other',
        };
    }

    public static function season(string $date): string
    {
        $m = (int) substr($date, 5, 2);

        return match (true) { $m === 10 => 'halloween', $m >= 6 && $m <= 8 => 'verano', $m === 12 => 'diciembre', default => 'resto' };
    }

    public function build(array $parties, array $payments, array $leads, string $from, string $to, string $today): array
    {
        $prevFrom = self::shiftYear($from, -1);
        $prevTo = self::shiftYear($to, -1);
        $in = fn (string $d, string $a, string $b) => $d >= $a && $d <= $b;
        $now = array_values(array_filter($parties, fn ($p) => $in($p['date'], $from, $to)));
        $before = array_values(array_filter($parties, fn ($p) => $in($p['date'], $prevFrom, $prevTo)));
        $paid = array_values(array_filter($payments, fn ($p) => $in($p['date'], $from, $to)));
        $paidBefore = array_values(array_filter($payments, fn ($p) => $in($p['date'], $prevFrom, $prevTo)));

        $out = [
            'from' => $from, 'to' => $to, 'previous' => ['from' => $prevFrom, 'to' => $prevTo], 'today' => $today,
            'kpis' => [
                'parties' => $this->pair(count($now), count($before)),
                'sales' => $this->pair($this->sum($now), $this->sum($before), $this->unknown($now)),
                'received' => $this->pair(array_sum(array_column($paid, 'amount_cents')), array_sum(array_column($paidBefore, 'amount_cents'))),
                'ticket' => $this->pair($this->average($now), $this->average($before)),
                'leads' => $this->pair(count(array_filter($leads, fn ($l) => $in($l['date'], $from, $to))), count(array_filter($leads, fn ($l) => $in($l['date'], $prevFrom, $prevTo)))),
            ],
            'sales' => $this->timeline($now, $from, $to, $before),
            'funnel' => $this->funnel(array_values(array_filter($leads, fn ($l) => $in($l['date'], $from, $to)))),
            'pulse' => $this->pulse(array_values(array_filter($paid, fn ($p) => $p['deposit'] ?? false)), array_values(array_filter($paidBefore, fn ($p) => $p['deposit'] ?? false)), $from, $to),
            // Owner 2026-10-29: the same view for the second half (balances) and for every payment.
            'pulse_balance' => $this->pulse(array_values(array_filter($paid, fn ($p) => ! ($p['deposit'] ?? false))), array_values(array_filter($paidBefore, fn ($p) => ! ($p['deposit'] ?? false))), $from, $to),
            'pulse_all' => $this->pulse($paid, $paidBefore, $from, $to),
            'channels' => $this->channels($now),
            'zones' => $this->zones($now),
            'booking_window' => $this->bookingWindow($parties),
            'profit' => $this->profit($now),
            'ahead' => $this->ahead($parties, $today),
        ];

        return [...$out, 'insights' => $this->insights($out)];
    }

    /**
     * Up to five things worth acting on, built only from the figures above. Each one is a small card:
     * tone (up|down|hot|cold|info), a short tag, the headline figure and one sentence.
     */
    private function insights(array $p): array
    {
        $usd = fn (int $c) => '$'.number_format(intdiv($c + 50, 100));
        $days = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
        $year = substr($p['previous']['from'], 0, 4);
        $out = [];
        $sales = $p['kpis']['sales'];
        if ($sales['change_pct'] !== null) {
            $out[] = ['tone' => $sales['change_pct'] >= 0 ? 'up' : 'down', 'tag' => 'Ventas vs '.$year, 'big' => ($sales['change_pct'] >= 0 ? '+' : '−').abs($sales['change_pct']).' %',
                'text' => $usd($sales['value']).' ahora contra '.$usd($sales['previous']).' en las mismas fechas de '.$year.'.'];
        }
        if ($p['pulse']['count'] >= 5) {
            $wd = $p['pulse']['weekday'];
            arsort($wd);
            $out[] = ['tone' => 'hot', 'tag' => 'Cuándo reservan', 'big' => ucfirst($days[array_key_first($wd)]),
                'text' => 'Los depósitos llegan más los '.$days[array_key_first($wd)].' y los días '.$p['pulse']['strongest_days'].' del mes. Responde rápido esos días.'];
        }
        $ch = array_values(array_filter($p['channels'], fn ($c) => $c['known'] >= 3 && $c['key'] !== 'unknown'));
        usort($ch, fn ($a, $b) => $b['average_cents'] <=> $a['average_cents']);
        if (count($ch) >= 2) {
            $out[] = ['tone' => 'info', 'tag' => 'Mejor canal', 'big' => $ch[0]['label'],
                'text' => 'Gastan '.$usd($ch[0]['average_cents']).' en promedio; por '.end($ch)['label'].', '.$usd(end($ch)['average_cents']).'.'];
        }
        $f = $p['funnel'];
        if ($f['count'] > 0) {
            $out[] = ['tone' => $f['conversion_pct'] >= 30 ? 'up' : 'down', 'tag' => 'Prospectos de la web', 'big' => $f['conversion_pct'].' %',
                'text' => $f['booked'].' de '.$f['count'].' reservaron'.($f['reasons'] ? '. Motivo de pérdida más común: '.mb_strtolower(array_key_first($f['reasons'])).'.' : '.')];
        }
        $sats = $p['ahead']['saturdays'];
        if ($sats) {
            $light = array_reduce($sats, fn ($a, $b) => $a === null || $b['parties'] < $a['parties'] ? $b : $a);
            $out[] = ['tone' => 'cold', 'tag' => 'Sábado para promocionar', 'big' => CarbonImmutable::parse($light['date'], 'America/Chicago')->locale('es')->translatedFormat('j M'),
                'text' => 'Lleva '.$light['parties'].($light['parties'] === 1 ? ' fiesta' : ' fiestas').'. Buen momento para un anuncio.'];
        }
        $today = CarbonImmutable::parse($p['today'], 'America/Chicago');
        foreach ([['verano', $today->month > 8 ? $today->year + 1 : $today->year, 6], ['halloween', $today->month > 10 ? $today->year + 1 : $today->year, 10], ['diciembre', $today->year, 12]] as [$season, $y, $m]) {
            $w = collect($p['booking_window'])->firstWhere('season', $season);
            $start = CarbonImmutable::create($y, $m, 1, 0, 0, 0, 'America/Chicago');
            if (! $w || $w['announce_weeks'] === null || $start <= $today) continue;
            $announce = $start->subWeeks($w['announce_weeks']);
            if ($announce->diffInDays($today, true) > 75) continue;
            $out[] = ['tone' => 'info', 'tag' => 'Anuncia '.mb_strtolower(self::SEASONS[$season]), 'big' => $announce->locale('es')->translatedFormat('j M'),
                'text' => $w['announce_weeks'].' semanas antes: así llegas a 3 de cada 4 clientes.'];
            break;
        }

        return array_slice($out, 0, 5);
    }

    private static function shiftYear(string $date, int $years): string
    {
        return CarbonImmutable::parse($date, 'America/Chicago')->addYearsNoOverflow($years)->toDateString();
    }

    private function pair(?int $value, ?int $previous, int $unknown = 0): array
    {
        $change = $value !== null && $previous ? (int) round(($value - $previous) * 100 / $previous) : null;

        return ['value' => $value, 'previous' => $previous, 'change_pct' => $change, 'unknown' => $unknown];
    }

    private function sum(array $rows): int
    {
        return array_sum(array_map(fn ($p) => $p['total_cents'] ?? 0, $rows));
    }

    private function unknown(array $rows): int
    {
        return count(array_filter($rows, fn ($p) => $p['total_cents'] === null));
    }

    private function average(array $rows): ?int
    {
        $known = array_values(array_filter(array_column($rows, 'total_cents'), fn ($v) => $v !== null));

        return $known ? (int) round(array_sum($known) / count($known)) : null;
    }

    /** Parties per day (≤ 31 days), per week (≤ 120 days) or per month, the same dates a year earlier, and who is in each bucket. */
    private function timeline(array $rows, string $from, string $to, array $lastYear = []): array
    {
        $f = CarbonImmutable::parse($from, 'America/Chicago');
        $t = CarbonImmutable::parse($to, 'America/Chicago');
        $days = (int) $f->diffInDays($t) + 1;
        $unit = $days <= 31 ? 'day' : ($days <= 120 ? 'week' : 'month');
        $buckets = [];
        for ($c = $unit === 'week' ? $f->startOfWeek(CarbonImmutable::MONDAY) : ($unit === 'month' ? $f->startOfMonth() : $f); $c <= $t;
             $c = $unit === 'day' ? $c->addDay() : ($unit === 'week' ? $c->addWeek() : $c->addMonthNoOverflow())) {
            $buckets[$c->toDateString()] = ['start' => $c->toDateString(), 'label' => $this->bucketLabel($unit, $c), 'parties' => 0, 'sales_cents' => 0, 'last_year' => 0, 'items' => []];
        }
        $key = function (string $date) use ($unit) {
            $d = CarbonImmutable::parse($date, 'America/Chicago');

            return ($unit === 'week' ? $d->startOfWeek(CarbonImmutable::MONDAY) : ($unit === 'month' ? $d->startOfMonth() : $d))->toDateString();
        };
        foreach ($rows as $p) {
            $k = $key($p['date']);
            if (! isset($buckets[$k])) continue;
            $buckets[$k]['parties']++;
            $buckets[$k]['sales_cents'] += $p['total_cents'] ?? 0;
            $buckets[$k]['items'][] = ['name' => $p['name'], 'date' => $p['date'], 'total_cents' => $p['total_cents'], 'source' => self::SOURCES[self::source($p['source'] ?? null)]];
        }
        foreach ($lastYear as $p) {
            $k = $key(self::shiftYear($p['date'], 1));
            if (isset($buckets[$k])) $buckets[$k]['last_year']++;
        }
        foreach ($buckets as &$b) usort($b['items'], fn ($x, $y) => $x['date'] <=> $y['date']);
        unset($b);
        $list = array_values($buckets);
        $best = $list ? array_reduce($list, fn ($b, $x) => $b === null || $x['parties'] > $b['parties'] ? $x : $b) : null;

        return ['unit' => $unit, 'buckets' => $list, 'max' => max([1, ...array_column($list, 'parties'), ...array_column($list, 'last_year')]),
            'best' => $best && $best['parties'] > 0 ? $best : null, 'has_last_year' => array_sum(array_column($list, 'last_year')) > 0];
    }

    private function bucketLabel(string $unit, CarbonImmutable $c): string
    {
        $c = $c->locale('es');

        return match ($unit) { 'day' => $c->translatedFormat('D j'), 'week' => $c->translatedFormat('j M'), default => ucfirst($c->translatedFormat('M y')) };
    }

    /** When booking deposits arrive: hot/cold weeks against the window average, day of month, weekday, and last year. */
    private function pulse(array $paid, array $paidBefore, string $from, string $to): array
    {
        $weeks = [];
        $f = CarbonImmutable::parse($from, 'America/Chicago')->startOfWeek(CarbonImmutable::MONDAY);
        $t = CarbonImmutable::parse($to, 'America/Chicago');
        for ($c = $f; $c <= $t; $c = $c->addWeek()) $weeks[$c->toDateString()] = ['start' => $c->toDateString(), 'count' => 0, 'cents' => 0, 'last_year' => 0];
        foreach ($paid as $p) {
            $k = CarbonImmutable::parse($p['date'], 'America/Chicago')->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
            if (isset($weeks[$k])) { $weeks[$k]['count']++; $weeks[$k]['cents'] += $p['amount_cents']; }
        }
        foreach ($paidBefore as $p) {
            $k = CarbonImmutable::parse(self::shiftYear($p['date'], 1), 'America/Chicago')->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
            if (isset($weeks[$k])) $weeks[$k]['last_year']++;
        }
        $avg = $weeks ? count($paid) / count($weeks) : 0;
        foreach ($weeks as &$w) $w['heat'] = $avg > 0 ? round($w['count'] / $avg, 2) : 0.0;
        unset($w);
        $ranges = ['1–5' => [1, 5], '6–10' => [6, 10], '11–15' => [11, 15], '16–20' => [16, 20], '21–25' => [21, 25], '26–31' => [26, 31]];
        $dom = $domCents = array_fill_keys(array_keys($ranges), 0);
        $weekday = $weekdayCents = array_fill(1, 7, 0);
        foreach ($paid as $p) {
            $d = (int) substr($p['date'], 8, 2);
            foreach ($ranges as $label => [$a, $b]) if ($d >= $a && $d <= $b) { $dom[$label]++; $domCents[$label] += $p['amount_cents']; }
            $n = (int) CarbonImmutable::parse($p['date'], 'America/Chicago')->format('N');
            $weekday[$n]++; $weekdayCents[$n] += $p['amount_cents'];
        }
        // Per day so the short 26–31 range is compared fairly.
        $perDay = [];
        foreach ($ranges as $label => [$a, $b]) $perDay[$label] = round($dom[$label] / ($b - $a + 1), 2);
        arsort($perDay);
        $sorted = array_values($weeks);
        usort($sorted, fn ($a, $b) => $b['count'] <=> $a['count']);

        return ['count' => count($paid), 'cents' => array_sum(array_column($paid, 'amount_cents')), 'average_per_week' => round($avg, 1),
            'weeks' => array_values($weeks), 'hottest' => ($sorted[0]['count'] ?? 0) > 0 ? $sorted[0] : null,
            'day_of_month' => $dom, 'day_of_month_cents' => $domCents, 'day_of_month_per_day' => $perDay, 'weekday_cents' => $weekdayCents, 'strongest_days' => array_key_first($perDay), 'weakest_days' => array_key_last($perDay),
            'weekday' => $weekday, 'last_year_count' => count($paidBefore)];
    }

    /** Website leads of the window: how many booked, how many were lost and why. */
    private function funnel(array $leads): array
    {
        $by = array_count_values(array_map(fn ($l) => $l['status'], $leads));
        $reasons = [];
        foreach ($leads as $l) if ($l['status'] === 'lost') {
            $label = \App\Models\LeadLossReason::LABELS[$l['reason'] ?? ''] ?? 'Sin motivo';
            $reasons[$label] = ($reasons[$label] ?? 0) + 1;
        }
        arsort($reasons);
        $closed = ($by['booked'] ?? 0) + ($by['lost'] ?? 0);

        return ['count' => count($leads), 'booked' => $by['booked'] ?? 0, 'lost' => $by['lost'] ?? 0, 'open' => count($leads) - $closed,
            'conversion_pct' => count($leads) ? (int) round(($by['booked'] ?? 0) * 100 / count($leads)) : null, 'reasons' => $reasons];
    }

    private function channels(array $rows): array
    {
        $out = [];
        foreach ($rows as $p) {
            $k = self::source($p['source'] ?? null);
            $out[$k] ??= ['key' => $k, 'label' => self::SOURCES[$k], 'parties' => 0, 'known' => 0, 'cents' => 0];
            $out[$k]['parties']++;
            if ($p['total_cents'] !== null) { $out[$k]['known']++; $out[$k]['cents'] += $p['total_cents']; }
        }
        foreach ($out as &$c) $c['average_cents'] = $c['known'] ? (int) round($c['cents'] / $c['known']) : null;
        unset($c);
        usort($out, fn ($a, $b) => $b['parties'] <=> $a['parties']);

        return $out;
    }

    private function zones(array $rows): array
    {
        $out = [];
        foreach ($rows as $p) {
            $zip = preg_match('/^\d{5}$/D', (string) ($p['zip'] ?? '')) ? $p['zip'] : null;
            if (! $zip) continue;
            $out[$zip] ??= ['zip' => $zip, 'city' => null, 'parties' => 0, 'cents' => 0];
            $out[$zip]['parties']++;
            $out[$zip]['cents'] += $p['total_cents'] ?? 0;
            $out[$zip]['city'] ??= ($p['city'] ?? null) ?: null;
        }
        usort($out, fn ($a, $b) => [$b['parties'], $b['cents']] <=> [$a['parties'], $a['cents']]);

        return array_slice($out, 0, 10);
    }

    /** How many weeks before the party people book, per season (all known history, not only the window). */
    private function bookingWindow(array $parties): array
    {
        $out = [];
        foreach (array_keys(self::SEASONS) as $s) $out[$s] = ['season' => $s, 'label' => self::SEASONS[$s], 'known' => 0, 'days' => []];
        foreach ($parties as $p) {
            if (empty($p['booked_on']) || $p['booked_on'] > $p['date']) continue;
            $out[self::season($p['date'])]['known']++;
            $out[self::season($p['date'])]['days'][] = (int) CarbonImmutable::parse($p['booked_on'], 'America/Chicago')->diffInDays(CarbonImmutable::parse($p['date'], 'America/Chicago'));
        }
        foreach ($out as &$s) {
            sort($s['days']);
            $n = count($s['days']);
            $s['median_weeks'] = $n ? round($s['days'][intdiv($n, 2)] / 7, 1) : null;
            // Three of every four bookings arrive within this many weeks of the party: announce by then.
            $s['announce_weeks'] = $n ? (int) floor($s['days'][intdiv($n * 3, 4)] / 7) : null;
            unset($s['days']);
        }
        unset($s);

        return array_values($out);
    }

    /**
     * Real profit = package sale − staff pay − paid staff transport − materials ($30 when no materials line).
     * Travel fee and card fee are not profit. Transport done by César or Maybeli is not paid, so it is never a line.
     */
    private function profit(array $rows): array
    {
        $ready = array_values(array_filter($rows, fn ($p) => is_array($p['profit'] ?? null)));
        $total = array_sum(array_map(fn ($p) => $p['profit']['revenue_cents'] - $p['profit']['cost_cents'], $ready));

        return ['ready' => count($ready), 'pending' => count($rows) - count($ready), 'total_cents' => $ready ? $total : null,
            'average_cents' => $ready ? (int) round($total / count($ready)) : null];
    }

    private function ahead(array $parties, string $today): array
    {
        $t = CarbonImmutable::parse($today, 'America/Chicago');
        $in4 = $t->addWeeks(4)->toDateString();
        $due = [];
        foreach ($parties as $p) {
            if ($p['date'] < $today || $p['date'] > $in4 || ! ($p['balance_cents'] ?? 0)) continue;
            $due[] = ['name' => $p['name'], 'date' => $p['date'], 'due' => $p['balance_due'] ?? CarbonImmutable::parse($p['date'], 'America/Chicago')->subDay()->toDateString(), 'cents' => $p['balance_cents']];
        }
        usort($due, fn ($a, $b) => $a['due'] <=> $b['due']);
        $unknownBalance = count(array_filter($parties, fn ($p) => $p['date'] >= $today && $p['date'] <= $in4 && ($p['balance_cents'] ?? null) === null && ($p['total_cents'] ?? null) !== null));
        $saturdays = [];
        for ($s = $t->next(CarbonImmutable::SATURDAY), $i = 0; $i < 8; $s = $s->addWeek(), $i++) $saturdays[$s->toDateString()] = ['date' => $s->toDateString(), 'parties' => 0];
        foreach ($parties as $p) if (isset($saturdays[$p['date']])) $saturdays[$p['date']]['parties']++;
        $next = $t->addMonthNoOverflow();
        $lastYear = $next->subYear();
        $repeat = [];
        foreach ($parties as $p) {
            if (substr($p['date'], 0, 7) !== $lastYear->format('Y-m') || empty($p['customer_id'])) continue;
            $rebooked = (bool) array_filter($parties, fn ($q) => ($q['customer_id'] ?? null) === $p['customer_id'] && $q['date'] > $p['date']);
            $repeat[$p['customer_id']] = ['name' => $p['name'], 'date' => $p['date'], 'rebooked' => $rebooked];
        }
        usort($repeat, fn ($a, $b) => $a['date'] <=> $b['date']);

        return ['receivable' => ['items' => $due, 'cents' => array_sum(array_column($due, 'cents')), 'unknown' => $unknownBalance, 'until' => $in4],
            'saturdays' => array_values($saturdays), 'anniversary' => ['month' => $next->format('Y-m'), 'items' => array_values($repeat)]];
    }
}
