<?php

namespace App\Domain\Attendance\Imports;

use App\Domain\Attendance\Models\GeoFence;
use App\Domain\Attendance\Services\GeoLocations;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Location;
use RuntimeException;

/** HROne parity HP-1b: geo work locations from a file (shared, per work location or per employee). */
final class GeoLocationImports extends RowImports
{
    public const TYPE = 'geo_locations';

    public const MODES = [
        'both' => ['Add and update', 'Add new places; update places with the same name and owner.'],
        'new' => ['New places only', 'Skip places already on file.'],
        'update' => ['Existing places only', 'Update places already on file.'],
    ];

    public static function fields(): array
    {
        return [
            'name' => ['Name', true], 'latitude' => ['Latitude', true], 'longitude' => ['Longitude', true], 'radius_m' => ['Radius (m)', false],
            'location_code' => ['Work location code', false], 'employee_code' => ['Employee code', false], 'address' => ['Address', false],
        ];
    }

    public function label(): string
    {
        return 'Geo location upload';
    }

    protected function module(): string
    {
        return 'attendance';
    }

    protected function entityType(): string
    {
        return GeoFence::class;
    }

    protected function aliases(): array
    {
        return ['lat' => 'latitude', 'lng' => 'longitude', 'long' => 'longitude', 'radius' => 'radius_m', 'location' => 'location_code', 'branch' => 'location_code'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return parent::prepare($import) + ['locations' => Location::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $c) => [mb_strtolower((string) $c) => (int) $id])->all()];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        $errors = [];
        try {
            app(GeoLocations::class)->assertPlace(['name' => $r['name'] ?? null, 'latitude' => $r['latitude'] ?? null, 'longitude' => $r['longitude'] ?? null, 'radius_m' => $r['radius_m'] ?? 200]);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
        $employeeId = null;
        if (isset($r['employee_code'])) {
            [$employeeId, $employeeErrors] = $this->employeeOf($r, $context);
            $errors = [...$errors, ...$employeeErrors];
        }
        $locationId = isset($r['location_code']) ? ($context['locations'][mb_strtolower($r['location_code'])] ?? null) : null;
        if (isset($r['location_code']) && $locationId === null) {
            $errors[] = "Work location code '{$r['location_code']}' does not exist.";
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }

        return ['action' => $this->existing($r['name'], $employeeId, $locationId) ? 'update' : 'create', 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $locationId = isset($r['location_code']) ? Location::query()->whereRaw('lower(code) = ?', [mb_strtolower($r['location_code'])])->value('id') : null;
        $values = ['name' => $r['name'], 'latitude' => (float) $r['latitude'], 'longitude' => (float) $r['longitude'], 'radius_m' => (int) ($r['radius_m'] ?? 200), 'address' => $r['address'] ?? null, 'employee_id' => $row->employee_id, 'location_id' => $locationId, 'status' => 'active'];
        $fence = $this->existing($r['name'], $row->employee_id, $locationId) ?? new GeoFence(['source' => 'import', 'created_by' => $user->getKey()]);
        $fence->fill($values)->withAuditReason($this->label()." #{$import->getKey()}")->save();

        return 'Geo location #'.$fence->id;
    }

    private function existing(string $name, ?int $employeeId, ?int $locationId): ?GeoFence
    {
        return GeoFence::query()->where('name', $name)
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId), fn ($q) => $q->whereNull('employee_id'))
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId), fn ($q) => $q->whereNull('location_id'))
            ->first();
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Name', 'Yes', 'Name of the place.', 'Head office'],
                ['Latitude', 'Yes', 'Decimal degrees, −90 to 90.', '28.6139'],
                ['Longitude', 'Yes', 'Decimal degrees, −180 to 180.', '77.2090'],
                ['Radius (m)', 'No', 'Check-ins within this distance count as inside (20 to 20000; default 200).', '200'],
                ['Work location code', 'No', 'For everyone posted at this work location.', 'DEL'],
                ['Employee code', 'No', 'For one employee only (field staff).', ''],
                ['Address', 'No', 'For reference.', 'Connaught Place, New Delhi'],
            ],
            'notes' => [
                'A place with neither a work location nor an employee applies to everyone.',
                'A place with the same name and the same owner is updated.',
            ],
        ];
    }

    protected function examples(): array
    {
        $location = Location::query()->orderBy('id')->value('code') ?? '';

        return [['Head office', '28.6139', '77.2090', '200', $location, '', 'Connaught Place, New Delhi']];
    }
}
