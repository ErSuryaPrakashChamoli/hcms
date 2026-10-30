<?php

namespace App\Filament\Pages;

use App\Domain\Documents\Services\Documents;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Services\SensitiveAccessAuditor;
use App\Domain\Experience\RequestTypes\ProfileChangeRequestType;
use App\Domain\Experience\Services\MyProfileSections;
use App\Domain\Experience\Services\ProfileSectionVisibility;
use App\Domain\Experience\Services\RequestTimeline;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\CurrentEmployee;
use App\Domain\People\Models\ProfileChangeRequest;
use App\Domain\People\Services\ProfileChanges;
use App\Domain\People\Support\ProfileEditSections;
use App\Domain\Workflow\Services\RequestReturns;
use App\Filament\Support\ProfileEditForms;
use App\Support\Storage\StagedUpload;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use RuntimeException;
use Throwable;
use UnitEnum;

/**
 * HROne parity MS-2 (My space → Profile): the employee's own profile, read by section — Snapshot, Personal, Family,
 * Professional, Skills, Other details, Bank, Previous employer, Documents, History — through MyProfileSections (every
 * value from its owning domain; the CA-6 profile-section matrix decides which sections the employee sees). The avatar
 * menu's "My profile" opens it; the Employee 360 stays the record HR and managers work in.
 *
 * HROne parity MS-3: each part the organisation lets the employee change (Setup → Core HR → Profile changes; default
 * none = read only, as before) has an "Edit" — a form with the reason and a supporting document, submitted through
 * ProfileChanges (approval, workflow or at once). A part with a change waiting shows "Change pending since … with …",
 * its details and "Withdraw"; sent back, "Correct and resubmit". History lists the person's profile change requests.
 *
 * Own identifiers and bank account numbers appear only with `employee.self_sensitive` (owner decision PRV-1 open; not in
 * the employee template): masked to the last four, and each "Show" is an audited sensitive view. The revealed list is
 * locked (the browser cannot add to it without the audited call).
 */
class MyProfile extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'My profile';

    protected static ?string $title = 'My profile';

    protected static ?string $slug = 'my-profile';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.my-profile';

    #[Url]
    public string $section = 'snapshot';

    /** @var list<string> fields shown in full on this page (each through reveal(), audited) */
    #[Locked]
    public array $revealed = [];

    public static function canAccess(): bool
    {
        $me = self::me();

        return $me !== null && auth()->user()->can('view', $me);
    }

    public static function me(): ?Employee
    {
        return auth()->check() ? app(CurrentEmployee::class)->of(auth()->user()) : null;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        if (! array_key_exists($this->section, $this->sections())) {
            $this->section = 'snapshot';
        }
    }

    /** @return array<string, string> */
    public function sections(): array
    {
        $me = self::me();

        return $me === null ? [] : app(MyProfileSections::class)->sections(auth()->user(), $me);
    }

    public function setSection(string $section): void
    {
        $this->section = array_key_exists($section, $this->sections()) ? $section : 'snapshot';
    }

    public function profile(): MyProfileSections
    {
        return app(MyProfileSections::class);
    }

    /** Show one own identifier or bank account number in full: only with employee.self_sensitive, own record only, audited. */
    public function reveal(string $field): void
    {
        $me = self::me();
        $user = auth()->user();
        if ($me === null || ! MyProfileSections::maySeeSensitive($user, $me)) {
            Notification::make()->danger()->title('Not available')->body('Your role does not show your identifiers or account numbers here. HR can help.')->send();

            return;
        }
        $matrix = app(ProfileSectionVisibility::class);
        $bank = preg_match('/^bank:(\d{1,18})$/', $field, $m) === 1 ? (int) $m[1] : null;
        $allowed = ($bank === null && array_key_exists($field, MyProfileSections::IDENTIFIERS) && $matrix->allowsAudience('employee', 'personal'))
            || ($bank !== null && $matrix->allowsAudience('employee', 'bank') && EmployeeBankAccount::query()->where('employee_id', $me->getKey())->whereKey($bank)->exists());
        if (! $allowed || in_array($field, $this->revealed, true)) {
            return;
        }
        app(SensitiveAccessAuditor::class)->recordView($me, 'self_reveal', $bank === null ? $field : 'bank_account:'.$bank);
        $this->revealed[] = $field;
    }

    public function conceal(string $field): void
    {
        $this->revealed = array_values(array_diff($this->revealed, [$field]));
    }

    // -- HROne parity MS-3: self-service profile changes ---------------------------------------------------------------

    public function changes(): ProfileChanges
    {
        return app(ProfileChanges::class);
    }

    /**
     * The parts of this tab the person may change, each with its waiting request (if any), whom it waits with and whether
     * it was sent back to them.
     *
     * @return list<array{section: string, label: string, pending: ?ProfileChangeRequest, returned: bool, note: ?string, waiting: list<string>}>
     */
    public function editUnits(string $tab): array
    {
        $me = self::me();
        if ($me === null) {
            return [];
        }
        $changes = $this->changes();
        $open = $changes->openRequests($me);
        $out = [];
        foreach (ProfileEditSections::onTab($tab) as $section) {
            $pending = $open->get($section);
            if ($pending === null && ! $changes->editable(auth()->user(), $me, $section)) {
                continue;
            }
            $return = $pending ? $changes->openReturn($pending) : null;
            $out[] = ['section' => $section, 'label' => ProfileEditSections::label($section), 'pending' => $pending,
                'returned' => $return !== null, 'note' => $return?->note, 'waiting' => $pending && $return === null ? $this->waitingWith($pending) : []];
        }

        return $out;
    }

    /** Why changes cannot be submitted now (lock, background verification), or null. */
    public function changesBlocked(): ?string
    {
        $me = self::me();

        return $me === null ? null : $this->changes()->blocked(auth()->user(), $me);
    }

    /** @return list<string> whom a pending request waits with (RQ-2: the workflow step's assignees or the approvers who have it now) */
    public function waitingWith(ProfileChangeRequest $request): array
    {
        try {
            return (array) (app(RequestTimeline::class)->forOwn(auth()->user(), app(ProfileChangeRequestType::class), $request)['waiting'] ?? []);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /** @return iterable<ProfileChangeRequest> the person's own profile change requests, newest first */
    public function changeHistory(): iterable
    {
        $me = self::me();

        return $me === null ? [] : $this->changes()->history(auth()->user(), $me);
    }

    public function editSectionAction(): Action
    {
        return Action::make('editSection')->label('Edit')->icon(Heroicon::OutlinedPencilSquare)->color('gray')->size('sm')
            ->visible(fn (array $arguments) => $this->mayEdit((string) ($arguments['section'] ?? '')))
            ->modalHeading(fn (array $arguments) => 'Change: '.ProfileEditSections::label((string) ($arguments['section'] ?? '')))
            ->modalDescription(fn (array $arguments) => $this->changes()->needsApproval((string) ($arguments['section'] ?? ''))
                ? 'The change shows on your profile once it is approved.' : 'The change shows on your profile at once (unless an approval workflow applies).')
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel('Submit')
            ->fillForm(fn (array $arguments) => ProfileEditForms::fill((string) ($arguments['section'] ?? ''), self::me()))
            ->schema(fn (array $arguments) => ProfileEditForms::schema((string) ($arguments['section'] ?? ''), self::me()))
            ->action(function (array $data, array $arguments) {
                $section = (string) ($arguments['section'] ?? '');
                [$input, $comment, $document] = ProfileEditForms::split($data);
                $file = null;
                try {
                    if ($document !== null) {
                        $file = StagedUpload::toUploadedFile((string) $document['staged']);
                        $document['file'] = $file;
                    }
                    $pending = $this->changes()->openRequests(self::me())->get($section);
                    $request = $pending !== null && $this->changes()->openReturn($pending) !== null
                        ? $this->changes()->revise($pending, auth()->user(), $input, $comment, $document)
                        : $this->changes()->submit(self::me(), auth()->user(), $section, $input, $comment, $document);
                    Notification::make()->success()->title(match (true) {
                        $request->status === 'approved' => 'Your profile is updated',
                        $pending !== null => 'Corrected and sent back for approval',
                        $request->route === 'workflow' => 'Change submitted: its approval workflow decides it',
                        default => 'Change submitted for approval',
                    })->body($request->reference())->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not submitted')->body($e->getMessage())->send();
                } finally {
                    if ($document !== null) {
                        StagedUpload::discard((string) $document['staged'], $file);
                    }
                }
            });
    }

    public function withdrawChangeAction(): Action
    {
        return Action::make('withdrawChange')->label('Withdraw')->color('gray')->size('sm')->requiresConfirmation()
            ->modalDescription('Nothing changes on your profile. You can ask again afterwards.')
            ->action(function (array $arguments) {
                try {
                    $this->changes()->withdraw($this->ownRequest($arguments), auth()->user());
                    Notification::make()->success()->title('Change withdrawn')->send();
                } catch (RuntimeException|AuthorizationException $e) {
                    Notification::make()->danger()->title('Not withdrawn')->body($e->getMessage())->send();
                }
            });
    }

    /** Resubmit a change sent back to the person without changing it (with an answer). */
    public function resubmitChangeAction(): Action
    {
        return Action::make('resubmitChange')->label('Resubmit as it is')->color('gray')->size('sm')
            ->schema([Textarea::make('response')->label('Your answer')->rows(2)->maxLength(2000)])
            ->action(function (array $data, array $arguments) {
                try {
                    $request = $this->ownRequest($arguments);
                    $return = $this->changes()->openReturn($request) ?? throw new RuntimeException('This change is not waiting for you.');
                    app(RequestReturns::class)->resubmit($return, auth()->user(), $data['response'] ?? null);
                    Notification::make()->success()->title('Sent back for approval')->send();
                } catch (RuntimeException|AuthorizationException $e) {
                    Notification::make()->danger()->title('Not resubmitted')->body($e->getMessage())->send();
                }
            });
    }

    /** The details of one own request: earlier / new per field (masked once decided), reason, documents, its trail. */
    public function changeDetailsAction(): Action
    {
        return Action::make('changeDetails')->label('Details')->color('gray')->size('sm')->link()
            ->modalHeading(fn (array $arguments) => ($r = $this->ownRequestOrNull($arguments)) ? 'Profile change '.$r->reference().' · '.ProfileEditSections::label((string) $r->section) : 'Profile change')
            ->modalSubmitAction(false)->modalCancelActionLabel('Close')
            ->modalContent(function (array $arguments) {
                $request = $this->ownRequestOrNull($arguments);
                if ($request === null) {
                    return null;
                }
                $trail = null;
                try {
                    $trail = app(RequestTimeline::class)->forOwn(auth()->user(), app(ProfileChangeRequestType::class), $request);
                } catch (Throwable $e) {
                    report($e);
                }

                return view('filament.pages.my-profile.change-details', [
                    'request' => $request,
                    'rows' => array_map(fn (array $f) => ['label' => $f['label'], 'earlier' => $f['earlier'] ?? null, 'new' => $f['new'] ?? null], (array) ($request->fields ?? [])),
                    'trail' => $trail,
                ]);
            });
    }

    private function mayEdit(string $section): bool
    {
        $me = self::me();
        if ($me === null || ! $this->changes()->editable(auth()->user(), $me, $section) || $this->changesBlocked() !== null) {
            return false;
        }
        $pending = $this->changes()->openRequests($me)->get($section);

        return $pending === null || $this->changes()->openReturn($pending) !== null;
    }

    private function ownRequest(array $arguments): ProfileChangeRequest
    {
        return $this->ownRequestOrNull($arguments) ?? throw new AuthorizationException('That change is not yours.');
    }

    /** A request of the signed-in person's own record only (never anyone else's, whatever the browser sends). */
    private function ownRequestOrNull(array $arguments): ?ProfileChangeRequest
    {
        $me = self::me();
        $id = (int) ($arguments['request'] ?? 0);
        if ($me === null || $id <= 0) {
            return null;
        }

        return ProfileChangeRequest::query()->withoutGlobalScope(AccessScope::class)->with('decider')->whereKey($id)->where('employee_id', $me->getKey())->first();
    }

    /** "Download all": the protected ZIP route (own documents only), when there is anything to download. */
    public function downloadAllUrl(): ?string
    {
        $me = self::me();

        return $me !== null && $this->profile()->downloadable(auth()->user(), $me)->isNotEmpty() ? route('me.documents.zip') : null;
    }

    /** @return array<int, string> document id => short-lived signed download link (the audited single download) */
    public function documentLinks(): array
    {
        $me = self::me();
        if ($me === null) {
            return [];
        }
        $documents = app(Documents::class);

        return $this->profile()->downloadable(auth()->user(), $me)->mapWithKeys(fn ($d) => [(int) $d->id => $documents->downloadUrl($d)])->all();
    }
}
