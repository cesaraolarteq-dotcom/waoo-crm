<?php

namespace App\Filament\Resources\Events\Pages\Concerns;

use App\Filament\Support\PartyHeader;
use Illuminate\Contracts\Support\Htmlable;

/** Owner 2026-11-02 audit: every tab of a party keeps the same header (its name, when and where); the tab name lives in the tabs. */
trait PartyPage
{
    public function getHeading(): string|Htmlable
    {
        return $this->record->title;
    }

    public function getSubheading(): ?string
    {
        return PartyHeader::subheading($this->record);
    }

    public function getBreadcrumbs(): array
    {
        return PartyHeader::breadcrumbs();
    }
}
