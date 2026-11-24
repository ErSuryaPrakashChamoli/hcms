<?php

namespace App\Domain\Organisation\Resolution;

use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * "Users manage names; PeopleOS manages codes and relationships." The one place an import (a single upload, a worksheet
 * of a master workbook, or anything else that reads typed references) turns a name or a code into a record id. See
 * docs/architecture/name-resolution.md.
 *
 *     $session = app(MasterResolver::class)->session($uploader, scope: "employee_imports:{$upload->id}");
 *     $r = $session->resolve('department', $row['department'], companyId: $companyId, sheet: 'Employees', row: 12);
 *     $r->ok() ? $r->id : $errors[] = $r->error();
 *     $upload->options['references'] = $session->report()->toArray();   // for the review
 */
final class MasterResolver
{
    public function __construct(private readonly MasterCatalogue $catalogue) {}

    /**
     * A session for one file. `actor` = the person whose access applies (the uploader, also when a queued job re-checks
     * the file); null = a system context with no access limit. `scope` = the upload, so mappings confirmed for it count.
     * `on` = the date applicability and "in force" are judged on (default today).
     */
    public function session(?User $actor = null, ?string $scope = null, CarbonInterface|string|null $on = null): ResolutionSession
    {
        $date = $on === null ? today() : Carbon::parse($on);

        return new ResolutionSession($this->catalogue, $actor, $scope, $date->toDateString());
    }

    /** One reference, outside any file (a session of one). */
    public function resolve(string $master, ?string $value, ?int $companyId = null, ?User $actor = null): Resolution
    {
        return $this->session($actor)->resolve($master, $value, $companyId);
    }

    public function catalogue(): MasterCatalogue
    {
        return $this->catalogue;
    }

    /** @param  class-string<Model>|string  $keyOrClass */
    public function keyFor(string $keyOrClass): string
    {
        return $this->catalogue->keyFor($keyOrClass);
    }
}
