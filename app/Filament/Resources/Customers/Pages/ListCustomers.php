<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Services\Google\GoogleContacts;
use Filament\Actions\{Action, ActionGroup, CreateAction};
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected static ?string $title = 'Clientes';

    protected static ?string $breadcrumb = 'Lista';

    public function mount(): void
    {
        parent::mount();
        if ($flash = session('google_contacts')) Notification::make()->title($flash)->{str_starts_with($flash, 'Contactos conectados') ? 'success' : 'warning'}()->send();
    }

    public function getSubheading(): ?string
    {
        $base = 'Todos los clientes, con su idioma y cuántos eventos tienen.';
        if (! GoogleContacts::configured()) return $base;
        $c = GoogleContacts::connection();
        if (! $c) return $base.' Conecta tus contactos para que cada cliente que reserva quede guardado en tu celular.';
        if ($c->last_error) return $base.' Contactos del celular: '.$c->last_error;
        $n = GoogleContacts::counts();
        return $base.' Contactos del celular ('.$c->google_email.'): '.$n['saved'].' '.($n['saved'] === 1 ? 'cliente guardado' : 'clientes guardados')
            .($n['pending'] ? ', '.$n['pending'].' por guardar' : '').'.';
    }

    // Shown only when the audited service exists (Resource::canCreate()).
    protected function getHeaderActions(): array
    {
        $connected = GoogleContacts::configured() && GoogleContacts::connection();
        return [
            Action::make('contactsConnect')->label('Guardar clientes en mi celular')->icon('heroicon-m-device-phone-mobile')->color('gray')
                ->visible(fn () => GoogleContacts::configured() && (! GoogleContacts::connection() || GoogleContacts::connection()->last_error))
                ->url(url('/admin/google/contacts/connect')),
            ActionGroup::make([
                Action::make('contactsSync')->label('Guardar los que faltan ahora')->icon('heroicon-m-arrow-path')->action(function () {
                    $r = app(GoogleContacts::class)->sync();
                    Notification::make()->{$r['failed'] ? 'warning' : 'success'}()->title($r['saved'] ? "Guardé {$r['saved']} en tus contactos" : 'Tus contactos están al día')
                        ->body($r['failed'] ? "{$r['failed']} no se pudieron guardar; lo reintento solo." : null)->send();
                }),
                Action::make('contactsDisconnect')->label('Desconectar mis contactos')->icon('heroicon-m-x-mark')->color('danger')->requiresConfirmation()
                    ->modalDescription('Los clientes que ya están en tu celular se quedan ahí. Solo dejo de guardar los nuevos.')
                    ->action(function () {
                        app(GoogleContacts::class)->disconnect();
                        Notification::make()->success()->title('Contactos desconectados')->send();
                    }),
            ])->label('Contactos del celular')->icon('heroicon-m-device-phone-mobile')->button()->color('gray')->visible(fn () => (bool) $connected),
            CreateAction::make()->visible(fn () => static::getResource()::canCreate())->label('Nuevo cliente'),
        ];
    }
}
