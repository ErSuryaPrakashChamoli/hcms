<?php

namespace App\Domain\ProductFeedback\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Support\PlatformOperator;
use App\Domain\ProductFeedback\Enums\ProductIdeaKind;
use App\Domain\ProductFeedback\Enums\ProductIdeaStatus;
use App\Domain\ProductFeedback\Models\ProductIdea;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HROne parity AC-3: the Markedge operators' read model of product ideas across organisations (Platform → Product ideas).
 * Operators only (PlatformOperator). It reads the ideas themselves — what each organisation wrote to Markedge — with the
 * organisation's name and the sender's name and work e-mail (Platform operations already shows the organisation's
 * contacts the same way); never any other tenant record.
 */
final class ProductIdeaDirectory
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  array{tenant?: ?int, module?: ?string, status?: ?string, kind?: ?string, search?: ?string}  $filters
     * @return Collection<int, ProductIdea> newest first
     */
    public function list(?User $actor, array $filters = [], int $limit = 100): Collection
    {
        PlatformOperator::assert($actor, 'see product ideas');

        return $this->tenants->bypass(fn () => $this->filtered($filters)
            ->with(['tenant:id,name,slug,status', 'submitter:id,name,email'])
            ->latest('id')->limit($limit)->get());
    }

    /**
     * Ideas per status under the other filters (for the status chips).
     *
     * @param  array{tenant?: ?int, module?: ?string, status?: ?string, kind?: ?string, search?: ?string}  $filters
     * @return array<string, int>
     */
    public function counts(?User $actor, array $filters = []): array
    {
        PlatformOperator::assert($actor, 'see product ideas');

        return $this->tenants->bypass(fn () => $this->filtered(['status' => null] + $filters)->reorder()
            ->select('status', DB::raw('count(*) as aggregate'))->groupBy('status')->toBase()->get()
            ->mapWithKeys(fn ($row) => [(string) $row->status => (int) $row->aggregate])->all());
    }

    public function find(?User $actor, ?int $id): ?ProductIdea
    {
        PlatformOperator::assert($actor, 'see product ideas');

        return $id === null ? null : $this->tenants->bypass(fn () => ProductIdea::query()->with(['tenant:id,name,slug,status', 'submitter:id,name,email'])->find($id));
    }

    /** @return array<int, string> the organisations that sent ideas: id => name */
    public function tenantOptions(?User $actor): array
    {
        PlatformOperator::assert($actor, 'see product ideas');
        $ids = $this->tenants->bypass(fn () => ProductIdea::query()->distinct()->pluck('tenant_id')->all());

        return Tenant::query()->whereIn('id', $ids)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @param  array<string, mixed>  $filters */
    private function filtered(array $filters): Builder
    {
        $status = ProductIdeaStatus::tryFrom((string) ($filters['status'] ?? ''));
        $kind = ProductIdeaKind::tryFrom((string) ($filters['kind'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));
        $id = ProductIdea::idFromReference($search);

        return ProductIdea::query()
            ->when(filled($filters['tenant'] ?? null), fn (Builder $q) => $q->where('tenant_id', (int) $filters['tenant']))
            ->when(filled($filters['module'] ?? null), fn (Builder $q) => $q->where('module', (string) $filters['module']))
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status->value))
            ->when($kind !== null, fn (Builder $q) => $q->where('kind', $kind->value))
            ->when($search !== '', fn (Builder $q) => $id !== null ? $q->whereKey($id)
                : $q->where(fn (Builder $q) => $q->where('title', 'like', '%'.$search.'%')->orWhere('process', 'like', '%'.$search.'%')));
    }
}
