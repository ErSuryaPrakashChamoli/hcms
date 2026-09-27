<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Datasets\AssetsDataset;
use App\Domain\Analytics\Datasets\AttendanceDataset;
use App\Domain\Analytics\Datasets\Dataset;
use App\Domain\Analytics\Datasets\EmployeesDataset;
use App\Domain\Analytics\Datasets\ExitsDataset;
use App\Domain\Analytics\Datasets\LearningDataset;
use App\Domain\Analytics\Datasets\LeaveDataset;
use App\Domain\Analytics\Datasets\PayrollDataset;
use App\Domain\Analytics\Datasets\PerformanceDataset;
use App\Domain\Analytics\Datasets\ServiceDeskDataset;
use App\Domain\Identity\Models\User;
use RuntimeException;

final class DatasetRegistry
{
    /** @var array<string, class-string<Dataset>> */
    private const DATASETS = [
        'employees' => EmployeesDataset::class, 'attendance' => AttendanceDataset::class, 'leave' => LeaveDataset::class, 'payroll' => PayrollDataset::class,
        'exits' => ExitsDataset::class, 'performance' => PerformanceDataset::class, 'learning' => LearningDataset::class, 'assets' => AssetsDataset::class, 'servicedesk' => ServiceDeskDataset::class,
    ];

    /** @return array<string, Dataset> */
    public function all(): array
    {
        return array_map(fn ($class) => app($class), self::DATASETS);
    }

    public function get(string $key): Dataset
    {
        $class = self::DATASETS[$key] ?? throw new RuntimeException("Unknown dataset '{$key}'.");

        return app($class);
    }

    /** @return array<string, Dataset> */
    public function availableTo(User $user): array
    {
        return array_filter($this->all(), fn (Dataset $d) => $d->allowedFor($user));
    }

    /** @return array<string, string> key => label */
    public function options(?User $user = null): array
    {
        return array_map(fn (Dataset $d) => $d->label(), $user ? $this->availableTo($user) : $this->all());
    }
}
