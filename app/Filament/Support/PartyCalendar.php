<?php

namespace App\Filament\Support;

use Filament\Forms\Components\Field;

/**
 * Owner 2026-10-21 ("el selector de fecha no es tan dinámico"): an always-visible month calendar instead of the
 * dropdown date picker. Arrows change month, past days are greyed out, a dot marks a day with one party and a pink
 * bar a day with two or more. State stays a Houston "Y-m-d" string, exactly like the DatePicker it replaces.
 */
class PartyCalendar extends Field
{
    protected string $view = 'filament.forms.party-calendar';

    /** @var array<string, int>|\Closure */
    protected array|\Closure $busy = [];

    protected ?string $minDate = null;

    public function busy(array|\Closure $days): static
    {
        $this->busy = $days;

        return $this;
    }

    /** @return array<string, int> "Y-m-d" => number of parties */
    public function getBusy(): array
    {
        return $this->evaluate($this->busy) ?? [];
    }

    public function minDate(?string $date): static
    {
        $this->minDate = $date;

        return $this;
    }

    public function getMinDate(): ?string
    {
        return $this->minDate;
    }
}
