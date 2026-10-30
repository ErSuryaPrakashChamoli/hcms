<?php

namespace App\Domain\Recognition\Services;

use App\Domain\Attendance\Services\HolidayResolver;
use App\Domain\Communication\Events\CommunicationEvent;
use App\Domain\Communication\Models\Announcement;
use App\Domain\Communication\Models\CommunicationRecipient;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Learning\Events\LearningEvent;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Events\EmployeeLifecycleChanged;
use App\Domain\Lifecycle\Events\EmployeeReminderDue;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\People\Services\Anniversaries;
use App\Domain\People\Services\Birthdays;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Recognition\Events\RecognitionEvent;
use App\Domain\Recognition\Models\Recognition;
use App\Domain\Recognition\Models\RewardPointEntry;
use App\Domain\Recognition\Models\WallComment;
use App\Domain\Recognition\Models\WallPost;
use App\Domain\Recognition\Models\WallReaction;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * HROne setup parity HP-9e (Engage → Social wall): the wall, off until engage.wall.enabled.
 *
 * - **Posts:** HR writes posts (wall.post; attachments while engage.wall.attachments); the system posts public badges,
 *   rewards between colleagues, celebrations (shared birthdays and wedding anniversaries, work anniversaries), published
 *   announcements, new joiners and — while engage.wall.show_achievements — completed goals and learning certificates.
 *   Each event is posted once (dedupe key); a withdrawn badge's post is hidden.
 * - **Who sees what:** wall.view (or wall.moderate) while the wall is on; with engage.wall.scope = company, posts of the
 *   viewer's own company (and organisation-wide ones); a viewer with an access scope sees posts about people in it; an
 *   announcement only its recipients; a celebration only those who may see that celebration (its own visible_to and
 *   sharing rules). Moderators also see hidden posts and comments.
 * - **Reactions and comments:** one reaction per person and post; comments unless the post's type is listed in
 *   engage.wall.comments_disabled_for; a comment is never edited (its author removes it, a moderator hides it).
 * - **Moderation:** wall.moderate hides and restores posts and comments with a reason, and pins posts; "Report" tells
 *   the moderators in-app.
 */
final class SocialWall
{
    public const BODY_MAX = 3000;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly TenantContext $tenants,
        private readonly AccessScopes $scopes,
        private readonly Notifier $notifier,
    ) {}

    /** Where attachments of HR posts are kept (DataInventory sweeps the folder with the tenant). */
    public static function directory(): string
    {
        return 'tenants/'.app(TenantContext::class)->id().'/wall';
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('engage.wall.enabled', false);
    }

    public function canView(User $user): bool
    {
        return $this->enabled() && ($user->hasPermission('wall.view') || $user->hasPermission('wall.moderate'));
    }

    public function moderates(User $user): bool
    {
        return $this->enabled() && $user->hasPermission('wall.moderate');
    }

    public function attachmentsAllowed(): bool
    {
        return (bool) $this->settings->get('engage.wall.attachments', true);
    }

    public function commentsAllowed(WallPost $post): bool
    {
        $group = WallPost::COMMENT_GROUPS[$post->type] ?? null;

        return $group === null || ! in_array($group, (array) $this->settings->get('engage.wall.comments_disabled_for', []), true);
    }

    /** The holiday the viewer's calendar has today, when the festive theme is on (null = none). */
    public function festiveHoliday(User $viewer): ?string
    {
        if (! $this->enabled() || ! (bool) $this->settings->get('engage.wall.festive_theme', false)) {
            return null;
        }
        $employee = app(RoleLens::class)->employee($viewer);
        if ($employee === null) {
            return null;
        }
        $today = Carbon::now($this->tenants->current()?->timezone ?: config('app.timezone'))->startOfDay();

        return app(HolidayResolver::class)->holidayOn($employee, Carbon::parse($today->toDateString()))?->name;
    }

    /**
     * HR writes a post on the wall (wall.post).
     *
     * @param  list<string>  $attachments  stored files in the tenant's wall folder
     */
    public function post(User $author, string $title, ?string $body = null, array $attachments = [], bool $pinned = false): WallPost
    {
        if (! $this->enabled()) {
            throw new RuntimeException('The social wall is switched off.');
        }
        if (! $author->hasPermission('wall.post')) {
            throw new RuntimeException('You cannot post on the social wall.');
        }
        $title = trim(strip_tags($title));
        $body = trim(strip_tags((string) $body));
        if ($title === '' || mb_strlen($title) > 200 || mb_strlen($body) > self::BODY_MAX) {
            throw new RuntimeException('A post needs a title of at most 200 characters and at most '.self::BODY_MAX.' characters of text.');
        }
        if ($attachments !== [] && ! $this->attachmentsAllowed()) {
            throw new RuntimeException('Attachments are not allowed on wall posts.');
        }
        $me = app(RoleLens::class)->employee($author);

        return WallPost::query()->create([
            'type' => 'hr_post', 'author_user_id' => $author->getKey(), 'title' => $title, 'body' => $body === '' ? null : $body,
            'attachments' => ($files = $this->checkedAttachments($attachments)) === [] ? null : $files, 'pinned' => $pinned && $author->hasPermission('wall.moderate'),
            'company_id' => $me?->currentPosition?->company_id,
        ]);
    }

    /**
     * A post the system makes for an event, once (dedupe key); nothing while the wall is off.
     *
     * @param  array<string, mixed>  $meta
     */
    public function system(string $type, ?Model $subject, ?Employee $about, string $title, ?string $body, string $dedupe, array $meta = []): ?WallPost
    {
        if (! $this->enabled()) {
            return null;
        }
        $about?->loadMissing('currentPosition');
        try {
            return WallPost::query()->firstOrCreate(['dedupe_key' => mb_substr($dedupe, 0, 80)], [
                'type' => $type, 'subject_type' => $subject?->getMorphClass(), 'subject_id' => $subject?->getKey(), 'employee_id' => $about?->id,
                'company_id' => $about?->currentPosition?->company_id, 'title' => mb_substr($title, 0, 200), 'body' => $body === null ? null : Str::limit($body, self::BODY_MAX),
                'meta' => $meta === [] ? null : $meta,
            ]);
        } catch (UniqueConstraintViolationException) {
            return WallPost::query()->where('dedupe_key', mb_substr($dedupe, 0, 80))->first();
        }
    }

    /**
     * The posts $viewer may see, newest first (pinned ones on top), with reaction and comment counts.
     *
     * @return Collection<int, WallPost>
     */
    public function feed(User $viewer, int $limit = 20, ?int $before = null): Collection
    {
        if (! $this->canView($viewer)) {
            return collect();
        }
        $posts = $this->visibleQuery($viewer)
            ->with(['aboutEmployee.person', 'author'])
            ->withCount(['reactions', 'comments' => fn (Builder $q) => $q->where('status', 'published')])
            ->when($before !== null, fn (Builder $q) => $q->where('id', '<', $before))
            ->orderByDesc('pinned')->orderByDesc('id')->limit($limit * 2)->get();
        $posts = $this->withoutHiddenCelebrations($posts, $viewer)->take($limit)->values();
        $mine = WallReaction::query()->whereIn('wall_post_id', $posts->pluck('id'))->where('user_id', $viewer->getKey())->pluck('reaction', 'wall_post_id');
        $posts->each(fn (WallPost $post) => $post->setAttribute('my_reaction', $mine[$post->id] ?? null));

        return $posts;
    }

    public function canSee(User $viewer, WallPost $post): bool
    {
        if (! $this->canView($viewer) || ! $this->visibleQuery($viewer)->whereKey($post->getKey())->exists()) {
            return false;
        }

        return $this->withoutHiddenCelebrations(collect([$post]), $viewer)->isNotEmpty();
    }

    /** @return Collection<int, WallComment> the comments of $post $viewer may read, oldest first */
    public function comments(WallPost $post, User $viewer): Collection
    {
        if (! $this->canSee($viewer, $post)) {
            return collect();
        }

        return $post->comments()->with('user')->when(! $this->moderates($viewer), fn ($q) => $q->where('status', 'published'))->orderBy('id')->get();
    }

    /** $user reacts to $post (null takes their reaction back). */
    public function react(WallPost $post, User $user, ?string $reaction = 'like'): ?WallReaction
    {
        $this->assertCanSee($user, $post);
        if ($reaction === null) {
            WallReaction::query()->where('wall_post_id', $post->id)->where('user_id', $user->getKey())->get()->each->delete();

            return null;
        }
        if (! array_key_exists($reaction, WallReaction::REACTIONS)) {
            throw new RuntimeException('Unknown reaction.');
        }

        try {
            $row = WallReaction::query()->firstOrNew(['wall_post_id' => $post->id, 'user_id' => $user->getKey()]);
            $row->reaction = $reaction;
            $row->save();

            return $row;
        } catch (UniqueConstraintViolationException) {
            return WallReaction::query()->where('wall_post_id', $post->id)->where('user_id', $user->getKey())->first();
        }
    }

    public function comment(WallPost $post, User $user, string $body): WallComment
    {
        $this->assertCanSee($user, $post);
        if (! $post->isPublished()) {
            throw new RuntimeException('This post was hidden.');
        }
        if (! $this->commentsAllowed($post)) {
            throw new RuntimeException('Comments are not allowed on this kind of post.');
        }
        $body = trim(strip_tags($body));
        if ($body === '' || mb_strlen($body) > WallComment::MAX) {
            throw new RuntimeException('Write a comment of at most '.WallComment::MAX.' characters.');
        }

        return WallComment::query()->create(['wall_post_id' => $post->id, 'user_id' => $user->getKey(), 'body' => $body]);
    }

    /** The author takes their comment back. */
    public function removeComment(WallComment $comment, User $user): WallComment
    {
        if ((int) $comment->user_id !== (int) $user->getKey() || $comment->status !== 'published') {
            throw new RuntimeException('Only its author can remove a comment.');
        }
        $comment->update(['status' => 'removed']);

        return $comment;
    }

    /** A moderator hides a post or a comment (with the reason); restore() brings it back. */
    public function hide(WallPost|WallComment $item, User $moderator, string $reason): WallPost|WallComment
    {
        if (! $this->moderates($moderator)) {
            throw new RuntimeException('Only wall moderators can hide posts and comments.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for hiding it.');
        }
        if ($item->status !== 'published') {
            throw new RuntimeException('It is not on the wall.');
        }
        $item->withAuditReason($reason)->update(['status' => 'hidden', 'hidden_at' => now(), 'hidden_by' => $moderator->getKey(), 'hide_reason' => mb_substr(trim($reason), 0, 255)]);

        return $item;
    }

    public function restore(WallPost|WallComment $item, User $moderator, string $reason): WallPost|WallComment
    {
        if (! $this->moderates($moderator)) {
            throw new RuntimeException('Only wall moderators can restore posts and comments.');
        }
        if ($item->status !== 'hidden') {
            throw new RuntimeException('Only a hidden post or comment can be restored.');
        }
        if ($item instanceof WallPost && $item->type === 'badge' && $item->subject instanceof Recognition && $item->subject->isWithdrawn()) {
            throw new RuntimeException('The badge was withdrawn; its post stays hidden.');
        }
        $item->withAuditReason($reason)->update(['status' => 'published', 'hidden_at' => null, 'hidden_by' => null, 'hide_reason' => null]);

        return $item;
    }

    public function pin(WallPost $post, User $moderator, bool $pinned = true): WallPost
    {
        if (! $this->moderates($moderator)) {
            throw new RuntimeException('Only wall moderators can pin posts.');
        }
        $post->update(['pinned' => $pinned]);

        return $post;
    }

    /** $reporter flags a post or comment: the moderators are told in-app. Returns how many were told. */
    public function report(WallPost|WallComment $item, User $reporter, ?string $reason = null): int
    {
        $post = $item instanceof WallPost ? $item : $item->post;
        $this->assertCanSee($reporter, $post);
        $moderators = User::query()->forCurrentTenant()->where('status', UserStatus::Active)->whereKeyNot($reporter->getKey())
            ->whereHas('roles.permissions', fn ($q) => $q->where('key', 'wall.moderate'))->limit(50)->get();
        $what = $item instanceof WallPost ? 'post "'.Str::limit($post->title, 60).'"' : 'comment on "'.Str::limit($post->title, 60).'"';
        $this->notifier->send($moderators, ['in_app'], 'A '.$what.' was reported on the social wall', trim((string) $reason) !== '' ? Str::limit(trim(strip_tags((string) $reason)), 300) : 'Please review it.', 'wall.reported', $post);

        return $moderators->count();
    }

    // ---- Posts made by the system (listeners) ------------------------------------------------------------------------

    /** Badges (public, when given), their points, and a withdrawn badge's post taken down; rewards between colleagues. */
    public function onRecognition(RecognitionEvent $event): void
    {
        $this->safely(function () use ($event) {
            $subject = $event->subject;
            if ($event->name === 'recognition.given' && $subject instanceof Recognition && $subject->visibility === 'public') {
                $subject->loadMissing(['badge', 'employee.person', 'giver']);
                $points = $subject->badge->show_points_on_wall ? (int) RewardPointEntry::query()->withoutGlobalScope(AccessScope::class)->where('dedupe_key', "badge:{$subject->id}")->value('points') : 0;
                $this->system('badge', $subject, $subject->employee, $subject->employee->person->display_name.' received the badge '.$subject->badge->name, $subject->message, "badge:{$subject->id}",
                    ['icon' => $subject->badge->icon, 'given_by' => $subject->giver?->name, 'points' => $points > 0 ? $points : null]);
            }
            if ($event->name === 'recognition.withdrawn' && $subject instanceof Recognition) {
                WallPost::query()->where('dedupe_key', "badge:{$subject->id}")->where('status', 'published')->get()
                    ->each(fn (WallPost $post) => $post->withAuditReason('Badge withdrawn')->update(['status' => 'hidden', 'hidden_at' => now(), 'hide_reason' => 'The badge was withdrawn.']));
            }
            if ($event->name === 'recognition.points_given' && $subject instanceof RewardPointEntry && $event->employee !== null) {
                $giver = Employee::query()->withoutGlobalScope(AccessScope::class)->with('person')->find($subject->counterpart_employee_id);
                $this->system('reward', $subject, $event->employee, ($giver?->person?->display_name ?? 'A colleague').' gave '.$event->employee->person?->display_name.' '.$subject->points.' reward points',
                    $subject->note, "reward:{$subject->id}", ['icon' => 'heroicon-o-gift', 'points' => (int) $subject->points]);
            }
        });
    }

    /** Celebrations of the day (the hourly birthday run): shared birthdays and wedding anniversaries, work anniversaries. */
    public function onReminder(EmployeeReminderDue $event): void
    {
        $kind = match ($event->name) {
            'employee.birthday' => 'birthday', 'employee.work_anniversary' => 'work_anniversary', 'employee.wedding_anniversary' => 'wedding_anniversary', default => null,
        };
        if ($kind === null || ! $this->enabled()) {
            return;
        }
        $this->safely(function () use ($event, $kind) {
            $employee = $event->employee->loadMissing('person');
            if (($kind === 'birthday' && ! $employee->person->birthday_shared) || ($kind === 'wedding_anniversary' && ! $employee->person->wedding_anniversary_shared)) {
                return;
            }
            $name = $employee->person->display_name;
            $title = match ($kind) {
                'birthday' => 'Happy birthday, '.$name.'!',
                'work_anniversary' => $name.' completes '.Anniversaries::yearsLabel((int) ($event->context['years'] ?? 1)).' with us',
                default => 'Happy wedding anniversary, '.$name.'!',
            };
            $day = Carbon::now($this->tenants->current()?->timezone ?: config('app.timezone'))->toDateString();
            $this->system('celebration', $employee, $employee, $title, null, "celebration:{$kind}:{$employee->id}:{$day}", ['kind' => $kind, 'icon' => $kind === 'birthday' ? 'heroicon-o-cake' : ($kind === 'work_anniversary' ? 'heroicon-o-trophy' : 'heroicon-o-heart')]);
        });
    }

    /** A published announcement (its recipients see it on the wall). */
    public function onCommunication(CommunicationEvent $event): void
    {
        if ($event->name !== 'communication.published' || ! $event->subject instanceof Announcement) {
            return;
        }
        $this->safely(function () use ($event) {
            $announcement = $event->subject;
            $text = trim(preg_replace('/\s+/', ' ', strip_tags(Str::markdown((string) $announcement->body))));
            $this->system('announcement', $announcement, null, (string) $announcement->title, Str::limit($text, 280), "announcement:{$announcement->id}", ['icon' => 'heroicon-o-megaphone']);
        });
    }

    /** A new joiner: the first time someone becomes a working employee. */
    public function onLifecycle(EmployeeLifecycleChanged $event): void
    {
        $joining = $event->to === LifecycleState::Joined
            || (in_array($event->to, [LifecycleState::Probation, LifecycleState::Confirmed, LifecycleState::Active], true)
                && in_array($event->from, [null, LifecycleState::PreEmployee, LifecycleState::Preboarding, LifecycleState::Onboarding], true));
        if (! $joining || ! $this->enabled()) {
            return;
        }
        $this->safely(function () use ($event) {
            $employee = $event->employee->loadMissing(['person', 'currentPosition.designation', 'currentPosition.department']);
            $role = collect([$employee->currentPosition?->designation?->name, $employee->currentPosition?->department?->name ? 'in '.$employee->currentPosition->department->name : null])->filter()->implode(' ');
            $this->system('new_joiner', $employee, $employee, 'Welcome, '.$employee->person->display_name.'!', $employee->person->display_name.' joins us'.($role !== '' ? ' as '.$role : '').'.',
                "joiner:{$employee->id}", ['icon' => 'heroicon-o-user-plus']);
        });
    }

    /** Achievements (engage.wall.show_achievements): a completed goal. */
    public function onPerformance(PerformanceEvent $event): void
    {
        if ($event->name !== 'performance.goal.completed' || $event->employee === null || ! $this->achievements()) {
            return;
        }
        $this->safely(fn () => $this->system('goal_achievement', $event->subject, $event->employee->loadMissing('person'),
            $event->employee->person->display_name.' achieved the goal "'.Str::limit((string) ($event->context['title'] ?? ''), 120).'"', null, "goal:{$event->subject->getKey()}", ['icon' => 'heroicon-o-flag']));
    }

    /** Achievements (engage.wall.show_achievements): a learning certificate issued. */
    public function onLearning(LearningEvent $event): void
    {
        if ($event->name !== 'learning.certificate.issued' || $event->employee === null || ! $this->achievements()) {
            return;
        }
        $this->safely(fn () => $this->system('learning_certificate', $event->subject, $event->employee->loadMissing('person'),
            $event->employee->person->display_name.' earned a certificate: '.Str::limit((string) ($event->context['course'] ?? ''), 120), null, "certificate:{$event->subject->getKey()}", ['icon' => 'heroicon-o-academic-cap']));
    }

    private function achievements(): bool
    {
        return $this->enabled() && (bool) $this->settings->get('engage.wall.show_achievements', false);
    }

    /** @return Builder<WallPost> */
    private function visibleQuery(User $viewer): Builder
    {
        $query = WallPost::query();
        $moderator = $this->moderates($viewer);
        if (! $moderator) {
            $query->where('status', 'published');
        }
        $me = app(RoleLens::class)->employee($viewer);
        if ($this->settings->get('engage.wall.scope', 'organisation') === 'company') {
            $company = $me?->currentPosition?->company_id;
            $query->where(fn (Builder $q) => $q->whereNull('company_id')->when($company !== null, fn (Builder $c) => $c->orWhere('company_id', $company)));
        }
        if ($this->scopes->isScoped($viewer)) {
            $query->where(fn (Builder $q) => $q->whereNull('employee_id')->orWhereIn('employee_id', $this->scopes->employeeKeys($viewer)));
        }
        if (! $moderator) {
            $announcement = (new Announcement)->getMorphClass();
            $query->where(fn (Builder $q) => $q->where('type', '!=', 'announcement')->orWhere(fn (Builder $a) => $a->where('subject_type', $announcement)
                ->whereIn('subject_id', CommunicationRecipient::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $me?->id ?? 0)->select('announcement_id'))));
        }

        return $query;
    }

    /**
     * Celebration posts only for viewers who may see that celebration (Birthdays / Anniversaries rules).
     *
     * @param  Collection<int, WallPost>  $posts
     * @return Collection<int, WallPost>
     */
    private function withoutHiddenCelebrations(Collection $posts, User $viewer): Collection
    {
        $celebrations = $posts->where('type', 'celebration');
        if ($celebrations->isEmpty() || $this->moderates($viewer)) {
            return $posts;
        }
        $visible = [];
        foreach ($celebrations->groupBy(fn (WallPost $p) => (string) ($p->meta['kind'] ?? 'birthday')) as $kind => $group) {
            $ids = $group->pluck('employee_id')->filter()->unique()->values()->all();
            $query = $kind === 'birthday' ? app(Birthdays::class)->query($viewer) : app(Anniversaries::class)->query($kind, $viewer);
            foreach ($query->whereIn('employees.id', $ids)->pluck('employees.id') as $id) {
                $visible[$kind.':'.$id] = true;
            }
        }

        return $posts->filter(fn (WallPost $p) => $p->type !== 'celebration' || isset($visible[($p->meta['kind'] ?? 'birthday').':'.$p->employee_id]))->values();
    }

    private function assertCanSee(User $user, WallPost $post): void
    {
        if (! $this->canSee($user, $post)) {
            throw new RuntimeException('You cannot see this post.');
        }
    }

    private function safely(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            // A wall post never stops the event it follows.
            report($e);
        }
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function checkedAttachments(array $paths): array
    {
        $paths = array_values(array_filter(array_map('strval', $paths)));
        $disk = Storage::disk(config('peopleos.documents.disk', 'local'));
        $prefix = self::directory().'/';
        foreach ($paths as $path) {
            if (! str_starts_with($path, $prefix) || str_contains($path, '..') || ! $disk->exists($path)) {
                throw new RuntimeException('Attach the file again: it could not be found.');
            }
        }

        return array_slice($paths, 0, 3);
    }
}
