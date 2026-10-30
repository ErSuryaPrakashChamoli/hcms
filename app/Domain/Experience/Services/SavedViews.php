<?php

namespace App\Domain\Experience\Services;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * HROne parity WO-1 ("Save Filters", saved filter list): a person's named views of a register — its filters, search,
 * sort, status lens, visible columns and layout — kept in their ExperiencePreferences (per person, inside their tenant,
 * like the rest of their presentation preferences). A view is presentation only: applying one sets the register's own
 * filters, which narrow the register's own query, so it can never show more than the person may see.
 *
 * Each register keeps at most `peopleos.bulk.saved_views_per_register` views per person; saving under an existing name
 * replaces that view; one view per register may be the default (applied when the register is opened afresh).
 */
final class SavedViews
{
    public const MAX_REGISTERS = 50;

    /** A view's stored state is capped (JSON) so a view cannot grow the preferences row without bound. */
    public const MAX_STATE_BYTES = 16384;

    public function __construct(private readonly ExperiencePreferences $preferences) {}

    public static function perRegister(): int
    {
        return max(1, (int) config('peopleos.bulk.saved_views_per_register', 20));
    }

    /** @return list<array<string, mixed>> */
    public function for(User $user, string $register): array
    {
        return $this->preferences->for($user)['saved_views'][$register] ?? [];
    }

    /** @return array<string, mixed>|null */
    public function find(User $user, string $register, string $id): ?array
    {
        return collect($this->for($user, $register))->firstWhere('id', $id);
    }

    /** @return array<string, mixed>|null */
    public function default(User $user, string $register): ?array
    {
        return collect($this->for($user, $register))->firstWhere('is_default', true);
    }

    /**
     * @param  array<string, mixed>  $state  filters, search, sort, lens, columns, layout, card_fields
     * @return array<string, mixed> the saved view
     */
    public function save(User $user, string $register, string $name, array $state, bool $default = false): array
    {
        self::assertRegister($register);
        $name = trim(mb_substr(strip_tags($name), 0, 60));
        if ($name === '') {
            throw new RuntimeException('Give the view a name.');
        }
        $views = $this->for($user, $register);
        $existing = collect($views)->first(fn (array $v) => mb_strtolower($v['name']) === mb_strtolower($name));
        if ($existing === null && count($views) >= self::perRegister()) {
            throw new RuntimeException('You can keep at most '.self::perRegister().' views on this register; delete one first.');
        }
        $view = self::view(['id' => $existing['id'] ?? Str::lower(Str::random(10)), 'name' => $name, 'is_default' => $default] + $state);
        if ($view === null) {
            throw new RuntimeException('This view is too large to save; clear some filters.');
        }
        $views = collect($views)->reject(fn (array $v) => $v['id'] === $view['id'])
            ->map(fn (array $v) => $default ? ['is_default' => false] + $v : $v)
            ->push($view)->values()->all();
        $this->write($user, $register, $views);

        return $view;
    }

    public function delete(User $user, string $register, string $id): void
    {
        $this->write($user, $register, collect($this->for($user, $register))->reject(fn (array $v) => $v['id'] === $id)->values()->all());
    }

    /** One default per register (null: none). */
    public function setDefault(User $user, string $register, ?string $id): void
    {
        $this->write($user, $register, collect($this->for($user, $register))->map(fn (array $v) => ['is_default' => $id !== null && $v['id'] === $id] + $v)->values()->all());
    }

    /** @param  list<array<string, mixed>>  $views */
    private function write(User $user, string $register, array $views): void
    {
        $all = $this->preferences->for($user)['saved_views'];
        $all[$register] = $views;
        if ($views === []) {
            unset($all[$register]);
        }
        $this->preferences->update($user, ['saved_views' => $all]);
    }

    private static function assertRegister(string $register): void
    {
        if (preg_match('/^[a-z0-9_.-]{1,64}$/', $register) !== 1) {
            throw new RuntimeException('Unknown register.');
        }
    }

    /**
     * The stored shape (ExperiencePreferences calls this on every write): known registers and fields only.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function normalise(mixed $value): array
    {
        $out = [];
        foreach ((array) $value as $register => $views) {
            if (! is_string($register) || preg_match('/^[a-z0-9_.-]{1,64}$/', $register) !== 1 || ! is_array($views) || count($out) >= self::MAX_REGISTERS) {
                continue;
            }
            $clean = array_values(array_filter(array_map(fn ($v) => is_array($v) ? self::view($v) : null, array_slice(array_values($views), 0, self::perRegister()))));
            // At most one default.
            $seenDefault = false;
            foreach ($clean as $i => $view) {
                if ($view['is_default'] && $seenDefault) {
                    $clean[$i]['is_default'] = false;
                }
                $seenDefault = $seenDefault || $view['is_default'];
            }
            if ($clean !== []) {
                $out[$register] = $clean;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>|null
     */
    private static function view(array $v): ?array
    {
        $id = is_string($v['id'] ?? null) && preg_match('/^[a-z0-9]{4,16}$/', $v['id']) === 1 ? $v['id'] : null;
        $name = is_string($v['name'] ?? null) ? trim(mb_substr($v['name'], 0, 60)) : '';
        if ($id === null || $name === '') {
            return null;
        }
        $strings = fn ($list, int $max) => array_values(array_slice(array_unique(array_filter((array) $list, fn ($s) => is_string($s) && $s !== '' && strlen($s) <= 100)), 0, $max));
        $view = [
            'id' => $id,
            'name' => $name,
            'filters' => is_array($v['filters'] ?? null) ? $v['filters'] : [],
            'search' => is_string($v['search'] ?? null) ? mb_substr($v['search'], 0, 200) : '',
            'sort' => is_string($v['sort'] ?? null) && preg_match('/^[A-Za-z0-9_.]{1,100}(:(asc|desc))?$/', $v['sort']) === 1 ? $v['sort'] : null,
            'lens' => is_string($v['lens'] ?? null) && strlen($v['lens']) <= 64 ? $v['lens'] : null,
            'columns' => $strings($v['columns'] ?? [], 50),
            'layout' => in_array($v['layout'] ?? null, ['table', 'cards'], true) ? $v['layout'] : null,
            'card_fields' => $strings($v['card_fields'] ?? [], 12),
            'is_default' => (bool) ($v['is_default'] ?? false),
        ];

        return strlen((string) json_encode($view)) > self::MAX_STATE_BYTES ? null : $view;
    }
}
