<?php
namespace App\Filament\Support;

use App\Filament\Pages;
use App\Filament\Resources;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;
use Filament\Support\Icons\Heroicon;

/**
 * Owner 2026-11-02: a calmer CRM. The sidebar shows only six sections (plus Reserva rápida); each section's pages are tabs at
 * the top of the page. Page URLs do not change: this only decides what the menu shows and which tabs a page gets.
 */
final class Sections
{
    /** @return array<string, array{label:string, icon:mixed, tabs:list<array{label:string, target:string}>}> */
    public static function all(): array
    {
        return [
            'quick' => ['label' => 'Reserva rápida', 'icon' => Heroicon::OutlinedBolt, 'tabs' => [['label' => 'Reserva rápida', 'target' => Pages\QuickBooking::class]]],
            'home' => ['label' => 'Inicio', 'icon' => Heroicon::OutlinedHome, 'tabs' => [['label' => 'Inicio', 'target' => Pages\Dashboard::class]]],
            'agenda' => ['label' => 'Agenda', 'icon' => Heroicon::OutlinedCalendarDays, 'tabs' => [
                ['label' => 'Calendario', 'target' => Pages\Calendar::class],
                ['label' => 'Planear el fin de semana', 'target' => Pages\WeekendPlan::class],
                ['label' => 'Lista de fiestas', 'target' => Resources\Events\EventResource::class],
            ]],
            'sales' => ['label' => 'Ventas', 'icon' => Heroicon::OutlinedChatBubbleLeftRight, 'tabs' => [
                ['label' => 'Prospectos', 'target' => Pages\Leads::class],
                ['label' => 'Cotizaciones', 'target' => Pages\Quotes::class],
                ['label' => 'Clientes', 'target' => Resources\Customers\CustomerResource::class],
            ]],
            'reports' => ['label' => 'Reportes', 'icon' => Heroicon::OutlinedChartBar, 'tabs' => [['label' => 'Reportes', 'target' => Pages\Reports::class]]],
            'team' => ['label' => 'Equipo', 'icon' => Heroicon::OutlinedUserGroup, 'tabs' => [
                ['label' => 'Staff y trajes', 'target' => Resources\Resources\ResourceUnitResource::class],
                ['label' => 'Personajes', 'target' => Resources\Characters\CharacterResource::class],
                ['label' => 'Trajes especiales', 'target' => Pages\CostumeExceptions::class],
                ['label' => 'Enlaces del staff', 'target' => Pages\StaffLinks::class],
                ['label' => 'Pago del staff', 'target' => Pages\StaffPay::class],
            ]],
            'settings' => ['label' => 'Ajustes', 'icon' => Heroicon::OutlinedCog6Tooth, 'tabs' => [
                ['label' => 'Catálogo', 'target' => Resources\Variants\ServiceVariantResource::class],
                ['label' => 'Contrato', 'target' => Pages\ContractTemplates::class],
                ['label' => 'Correos', 'target' => Pages\EmailTemplates::class],
                ['label' => 'Seguridad', 'target' => 'route:security'],
                ['label' => 'HoneyBook', 'target' => 'section:honeybook'],
            ]],
            // Second row inside Ajustes → HoneyBook. Leaves the menu once no party is managed by HoneyBook any more.
            'honeybook' => ['label' => 'HoneyBook', 'icon' => null, 'parent' => 'settings', 'tabs' => [
                ['label' => 'Importar', 'target' => Pages\ImportHoneyBook::class],
                ['label' => 'Historial', 'target' => Pages\ImportHistory::class],
                ['label' => 'Revisar calendario', 'target' => Pages\CalendarIdentities::class],
                ['label' => 'Por organizar', 'target' => Pages\HoneyBookInformation::class],
                ['label' => 'Saldos y origen', 'target' => Pages\HoneyBookArchiveReview::class],
                ['label' => 'Cambio final', 'target' => Pages\HoneyBookCutover::class],
            ]],
        ];
    }

    public static function navigation(NavigationBuilder $builder): NavigationBuilder
    {
        $items = []; $sort = 0;
        foreach (self::all() as $key => $section) {
            if (isset($section['parent'])) continue;
            $tabs = self::visibleTabs($key);
            if ($tabs === []) continue;
            $items[] = NavigationItem::make($section['label'])->icon($section['icon'])->url($tabs[0]['url'])->sort(++$sort)
                ->isActiveWhen(fn () => self::current() !== null && in_array(self::current(), [$key, ...self::childrenOf($key)], true));
        }
        return $builder->items($items);
    }

    /** The section (key) the current request belongs to, or null. */
    public static function current(): ?string
    {
        foreach (self::all() as $key => $section) {
            foreach ($section['tabs'] as $tab) if (self::isActive($tab['target'])) return $key;
        }
        return null;
    }

    /** Tab rows for the current page: the parent row first (with the child tab active), then the child row. */
    public static function rows(): array
    {
        if (self::eventPage()) return []; // a party's tabs sit under its header (filament.party-bar)
        $key = self::current();
        if ($key === null) return [];
        $parent = self::all()[$key]['parent'] ?? null;
        $rows = [];
        if ($parent) $rows[] = self::visibleTabs($parent, 'section:'.$key);
        $rows[] = self::visibleTabs($key);
        return array_values(array_filter($rows, fn ($r) => count($r) > 1 || $parent !== null));
    }

    /** @return list<array{label:string,url:string,active:bool}> */
    public static function visibleTabs(string $key, ?string $activeTarget = null): array
    {
        $out = [];
        foreach (self::all()[$key]['tabs'] as $tab) {
            $url = self::url($tab['target']);
            if ($url === null) continue;
            $out[] = ['label' => $tab['label'], 'url' => $url, 'active' => $activeTarget !== null ? $tab['target'] === $activeTarget : self::isActive($tab['target'])];
        }
        return $out;
    }

    /**
     * One party's file: Resumen · Dinero · Contrato · Equipo (owner 2026-11-02), on each of those pages. Each tab shows only when
     * its page is available for this party (same gates as the old buttons).
     */
    /** The party whose file is on screen (Resumen, Dinero, Contrato, Equipo, Costos), or null. */
    public static function eventPage(): ?\App\Models\Event
    {
        $route = request()->route();
        $base = \App\Filament\Resources\Events\EventResource::getRouteBaseName().'.';
        $name = $route?->getName();
        if (! $name || ! str_starts_with($name, $base) || ! in_array(substr($name, strlen($base)), ['view', 'money', 'contract', 'confirm', 'costs'], true)) return null;
        $record = $route->parameter('record');

        return $record instanceof \App\Models\Event ? $record : \App\Models\Event::find($record);
    }

    public static function eventTabs(): ?array
    {
        $route = request()->route();
        $base = \App\Filament\Resources\Events\EventResource::getRouteBaseName().'.';
        $name = $route?->getName();
        if (! $name || ! str_starts_with($name, $base)) return null;
        $page = substr($name, strlen($base));
        if (! in_array($page, ['view', 'money', 'contract', 'confirm', 'costs'], true)) return null;
        $record = $route->parameter('record');
        $event = $record instanceof \App\Models\Event ? $record : \App\Models\Event::find($record);
        if (! $event) return null;
        $url = fn (string $p) => \App\Filament\Resources\Events\EventResource::getUrl($p, ['record' => $event]);
        $tabs = [['label' => 'Resumen', 'url' => $url('view'), 'active' => $page === 'view']];
        if (FinanceGate::enabled()) $tabs[] = ['label' => 'Dinero', 'url' => $url('money'), 'active' => $page === 'money'];
        if (DocumentsGate::enabled()) $tabs[] = ['label' => 'Contrato', 'url' => $url('contract'), 'active' => $page === 'contract'];
        if (\App\Filament\Resources\Events\Pages\ConfirmCapacity::canAccessFor($event) || $page === 'confirm') $tabs[] = ['label' => 'Equipo', 'url' => $url('confirm'), 'active' => $page === 'confirm'];
        // A confirmed party's team is chosen the week of the party, together with the rest of that weekend.
        elseif ($event->status === 'capacity_reserved' && \App\Filament\Pages\WeekendPlan::canAccess()) $tabs[] = ['label' => 'Equipo', 'url' => \App\Filament\Pages\WeekendPlan::getUrl(['fiesta' => $event->id]), 'active' => false];
        if ($page === 'costs') $tabs[] = ['label' => 'Costos', 'url' => $url('costs'), 'active' => true];
        return count($tabs) > 1 ? $tabs : null;
    }

    private static function childrenOf(string $key): array
    {
        return array_keys(array_filter(self::all(), fn ($s) => ($s['parent'] ?? null) === $key));
    }

    private static function url(string $target): ?string
    {
        try {
            if ($target === 'route:security') return route('security');
            if (str_starts_with($target, 'section:')) {
                $child = substr($target, 8);
                if ($child === 'honeybook' && ! self::honeyBookStillUsed()) return null;
                return self::visibleTabs($child)[0]['url'] ?? null;
            }
            if (is_subclass_of($target, \Filament\Resources\Resource::class)) return $target::canViewAny() ? $target::getUrl('index') : null;
            return $target::canAccess() ? $target::getUrl() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function isActive(string $target): bool
    {
        $route = request()->route()?->getName();
        if (! $route) return false;
        if ($target === 'route:security') return $route === 'security';
        if (str_starts_with($target, 'section:')) return false;
        if (is_subclass_of($target, \Filament\Resources\Resource::class)) return str_starts_with($route, $target::getRouteBaseName().'.');
        return $route === $target::getRouteName();
    }

    /** HoneyBook pages stay until every imported party is managed by the CRM (after the final switch). */
    private static function honeyBookStillUsed(): bool
    {
        return ! \App\Models\ImportedProjectLink::query()->exists()
            || \App\Models\Event::whereIn('id', \App\Models\ImportedProjectLink::select('event_id'))->where('responsible_system', 'honeybook')->exists();
    }
}
