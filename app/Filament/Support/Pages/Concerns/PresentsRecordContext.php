<?php

namespace App\Filament\Support\Pages\Concerns;

use App\Domain\Experience\Services\ModuleContext;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * UX.15 closure: context for a record page (create, edit, view). Breadcrumbs name the area and the record
 * ("Departments › Engineering"), not the page type ("› Edit"); the subheading says what state the record is
 * in and what a change does. It reads only the record's own status, effective dates and timestamps, which
 * the page already shows or governs, so it exposes nothing new.
 */
trait PresentsRecordContext
{
    /** @return array<string, string>|list<string> */
    public function getBreadcrumbs(): array
    {
        $resource = static::getResource();
        $crumbs = [];
        if ($resource::hasPage('index') && $resource::canViewAny()) {
            $crumbs[$resource::getUrl('index')] = (string) $resource::getTitleCasePluralModelLabel();
        }
        $record = property_exists($this, 'record') ? $this->record : null;
        if ($record instanceof Model && $record->exists && $resource::hasRecordTitle()) {
            $crumbs[] = (string) $this->getRecordTitle();
        }

        return $crumbs;
    }

    /** One line: the record's state, its effective window and its last change; on forms, what saving does. */
    public function getSubheading(): ?string
    {
        $record = property_exists($this, 'record') ? $this->record : null;
        $parts = [];
        if ($record instanceof Model && $record->exists) {
            $attributes = $record->getAttributes();
            $date = fn (mixed $v): ?CarbonInterface => $v === null || $v === '' ? null : ($v instanceof CarbonInterface ? $v : Carbon::parse($v));
            if (array_key_exists('status', $attributes) && filled($attributes['status'])) {
                $parts[] = $this->statusLabel($record);
            }
            if (array_key_exists('effective_from', $attributes) || array_key_exists('effective_to', $attributes)) {
                // Raw attributes: a model may have only one of the two columns (strict mode forbids reading the other).
                $from = $date($attributes['effective_from'] ?? null);
                $to = $date($attributes['effective_to'] ?? null);
                $parts[] = match (true) {
                    $from !== null && $from->isFuture() => 'starts '.$from->format('j M Y'),
                    $to !== null && $to->copy()->endOfDay()->isPast() => 'ended '.$to->format('j M Y'),
                    $from !== null && $to !== null => 'in effect '.$from->format('j M Y').' – '.$to->format('j M Y'),
                    $from !== null => 'in effect since '.$from->format('j M Y'),
                    $to !== null => 'in effect until '.$to->format('j M Y'),
                    default => 'in effect, open-ended',
                };
            }
            if (filled($attributes['updated_at'] ?? null) && ($changed = $date($attributes['updated_at'])) instanceof CarbonInterface) {
                $parts[] = 'last changed '.$changed->diffForHumans();
            }
        }
        $line = $parts === [] ? '' : Str::ucfirst(implode(' · ', $parts)).'.';

        return trim($line.' '.$this->recordPageNote()) ?: null;
    }

    /** What this page does with a change (forms); nothing on read-only pages. */
    protected function recordPageNote(): string
    {
        return '';
    }

    private function statusLabel(Model $record): string
    {
        $raw = $record->getAttribute('status');
        if ($raw instanceof HasLabel) {
            return (string) $raw->getLabel();
        }
        $value = $raw instanceof BackedEnum ? (string) $raw->value : (string) $raw;

        return ModuleContext::STATUSES[$value][0] ?? Str::ucfirst(str_replace('_', ' ', $value));
    }
}
