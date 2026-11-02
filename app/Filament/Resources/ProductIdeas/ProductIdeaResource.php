<?php

namespace App\Filament\Resources\ProductIdeas;

use App\Domain\ProductFeedback\Enums\ProductIdeaKind;
use App\Domain\ProductFeedback\Enums\ProductIdeaStatus;
use App\Domain\ProductFeedback\Models\ProductIdea;
use App\Domain\ProductFeedback\Services\ProductIdeas;
use App\Filament\Resources\ProductIdeas\Pages\ListProductIdeas;
use App\Filament\Resources\ProductIdeas\Pages\ViewProductIdea;
use App\Http\Controllers\ParityFileController;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * HROne parity AC-3 (matrix 23 §23.9 #22, HROne's "Product enhancement request" and roadmap): the organisation's product
 * ideas to Markedge — "Suggest an improvement" (a step wizard: what it is about, the challenge, the ideal solution, impact
 * and files, review; `?action=create` opens it) and the list of the organisation's ideas with the status and Markedge's
 * response. product.feedback (ProductIdeaPolicy); the tenant scope keeps other organisations' ideas out. Under Platform
 * with the organisation's other dealings with Markedge (Data export), and on the Lifecycle desk.
 */
class ProductIdeaResource extends Resource
{
    protected static ?string $model = ProductIdea::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Product ideas';

    protected static ?string $modelLabel = 'product idea';

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $slug = 'product-ideas';

    protected static ?int $navigationSort = 61;

    /** The organisation's own screen: Markedge's operators read and answer ideas on Platform → Product ideas instead. */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ! $user->isPlatformAdmin() && parent::canAccess();
    }

    /** Ideas are sent by the organisation's own people (platform operators answer them on Platform → Product ideas). */
    public static function canCreate(): bool
    {
        return auth()->user()?->getAttribute('tenant_id') !== null && parent::canCreate();
    }

    /** The URL that opens "Suggest an improvement" directly. */
    public static function suggestUrl(): string
    {
        return static::getUrl('index', ['action' => 'create']);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('submitter:id,name');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What it is about')->description('The part of PeopleOS and the process the idea is for.')->columns(2)->schema([
                Select::make('module')->label('Module')->options(fn () => ProductIdeas::moduleOptions())->searchable()->native(false)->required(),
                TextInput::make('process')->label('Process')->placeholder('e.g. attendance regularisation, full and final settlement')->required()->minLength(3)->maxLength(150),
                Radio::make('kind')->label('Is this a new feature or an improvement?')->required()->columnSpanFull()
                    ->options([ProductIdeaKind::NewFeature->value => ProductIdeaKind::NewFeature->question(), ProductIdeaKind::Improvement->value => ProductIdeaKind::Improvement->question()]),
                TextInput::make('title')->label('Your idea in one line')->required()->minLength(5)->maxLength(150)->columnSpanFull(),
            ]),
            Section::make('The challenge')->description('What gets in the way today. The more specific, the better Markedge can judge it.')->schema([
                Textarea::make('challenge')->label('What problem or challenge do you face?')->required()->minLength(10)->maxLength(5000)->rows(4)
                    ->helperText('How it affects your day-to-day work and your people.'),
                Textarea::make('current_workaround')->label('How do you manage it today?')->maxLength(5000)->rows(3)
                    ->helperText('The workaround, tools or manual steps you rely on now.'),
            ]),
            Section::make('The ideal solution')->description('Your picture of the right answer: what it should do and what would improve.')->schema([
                Textarea::make('ideal_solution')->label('What would an ideal solution look like?')->required()->minLength(10)->maxLength(5000)->rows(5),
            ]),
            Section::make('Impact and files')->description('Optional: how much it matters, and screenshots or documents that show it.')->columns(2)->schema([
                Select::make('priority')->label('How much does it affect your work?')->options(ProductIdea::PRIORITIES)->native(false),
                TextInput::make('impact_people')->label('People affected (about)')->numeric()->integer()->minValue(0)->maxValue(10_000_000),
                FileUpload::make('attachments')->label('Files')->multiple()->maxFiles(ProductIdeas::MAX_FILES)->columnSpanFull()
                    ->disk(ProductIdeas::diskName())->directory(rtrim(ProductIdeas::UPLOAD_AREA, '/'))->visibility('private')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize((int) config('peopleos.documents.max_kb', 10240))
                    ->storeFileNamesIn('attachment_names')
                    ->helperText('Up to '.ProductIdeas::MAX_FILES.' files (PDF, JPG, PNG). Markedge\'s product team sees what you write here and these files: leave out employees\' personal data.'),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Idea')->columns(3)->schema([
                TextEntry::make('reference'),
                TextEntry::make('status')->badge(),
                TextEntry::make('kind')->label('Kind'),
                TextEntry::make('title')->columnSpanFull(),
                TextEntry::make('module')->formatStateUsing(fn (?string $state) => ProductIdeas::moduleLabel($state)),
                TextEntry::make('process'),
                TextEntry::make('submitter.name')->label('Sent by')->placeholder('—'),
                TextEntry::make('submitted_at')->label('Sent on')->dateTime('j M Y, H:i'),
                TextEntry::make('status_changed_at')->label('Status since')->dateTime('j M Y, H:i')->placeholder('—'),
            ]),
            Section::make('Markedge\'s response')->schema([
                TextEntry::make('platform_response')->hiddenLabel()->columnSpanFull()
                    ->placeholder('No response yet. Markedge\'s product team replies here, and you are told when the status changes.')
                    ->extraAttributes(['class' => 'whitespace-pre-line']),
            ]),
            Section::make('The challenge')->schema([
                TextEntry::make('challenge')->label('The problem or challenge')->extraAttributes(['class' => 'whitespace-pre-line']),
                TextEntry::make('current_workaround')->label('How it is managed today')->placeholder('—')->extraAttributes(['class' => 'whitespace-pre-line']),
                TextEntry::make('ideal_solution')->label('The ideal solution')->extraAttributes(['class' => 'whitespace-pre-line']),
            ]),
            Section::make('Impact and files')->columns(3)->schema([
                TextEntry::make('priority')->label('How much it affects the work')->state(fn (ProductIdea $record) => $record->priorityLabel())->placeholder('—'),
                TextEntry::make('impact_people')->label('People affected (about)')->numeric()->placeholder('—'),
                TextEntry::make('files')->label('Files')->html()->placeholder('None')
                    ->state(fn (ProductIdea $record) => self::fileLinks($record)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $modules = collect(ProductIdeas::moduleOptions())->flatMap(fn (array $group) => $group)->all();

        return $table
            ->columns([
                TextColumn::make('reference')->searchable()->sortable(),
                TextColumn::make('title')->searchable()->wrap()->limit(80),
                TextColumn::make('module')->formatStateUsing(fn (?string $state) => ProductIdeas::moduleLabel($state))->toggleable(),
                TextColumn::make('kind')->label('Kind')->toggleable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('platform_response')->label('Markedge\'s response')->limit(60)->placeholder('—')->toggleable(),
                TextColumn::make('submitter.name')->label('Sent by')->placeholder('—')->toggleable(),
                TextColumn::make('submitted_at')->label('Sent on')->date('j M Y')->sortable(),
                TextColumn::make('status_changed_at')->label('Status since')->since()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(ProductIdeaStatus::options())->multiple(),
                SelectFilter::make('module')->options($modules),
                SelectFilter::make('kind')->options(ProductIdeaKind::options()),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('No product ideas yet')
            ->emptyStateDescription('Tell Markedge what PeopleOS should do better: Suggest an improvement.')
            ->recordActions([ViewAction::make()]);
    }

    public static function fileLinks(ProductIdea $record): ?HtmlString
    {
        $paths = array_values((array) ($record->attachments ?? []));
        if ($paths === []) {
            return null;
        }

        return new HtmlString(collect($paths)->map(fn (string $path, int $i) => '<a class="text-primary-600 underline" target="_blank" rel="noopener" href="'
            .e(ParityFileController::url('product-idea', $record, $i)).'">'.e(basename($path)).'</a>')->implode('<br>'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductIdeas::route('/'),
            'view' => ViewProductIdea::route('/{record}'),
        ];
    }
}
