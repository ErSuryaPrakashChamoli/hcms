<?php

namespace App\Filament\Support\Website;

use App\Domain\Website\Services\Website;
use App\Domain\Website\Support\SiteDefaults;
use App\Domain\Website\Support\SiteIcons;
use App\Domain\Website\Support\SiteLink;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use InvalidArgumentException;

/**
 * The public website editor's forms (Platform → Public website). Each area opens as a short step-by-step wizard; a page's
 * sections are a Builder of the block types the site renders (resources/views/website/blocks). Links accept only site
 * paths, https addresses, mailto: and tel: (SiteLink).
 */
final class WebsiteForms
{
    public static function link(string $name, string $label = 'Link', bool $required = false): TextInput
    {
        return TextInput::make($name)->label($label)->maxLength(500)->placeholder('/pricing or https://…')
            ->rule(fn () => SiteLink::rule())->required($required);
    }

    public static function icon(string $name = 'icon'): Select
    {
        return Select::make($name)->label('Icon')->options(SiteIcons::pickerOptions())->searchable()->native(false)->placeholder('No icon');
    }

    public static function hue(string $name = 'hue'): Select
    {
        return Select::make($name)->label('Colour')->options(SiteIcons::HUES)->native(false)->default('iris')->selectablePlaceholder(false);
    }

    public static function visual(string $name = 'visual', string $default = 'workspace'): Select
    {
        return Select::make($name)->label('Illustration')->options(SiteDefaults::VISUALS)->native(false)->default($default)->selectablePlaceholder(false)
            ->helperText('Drawn by the site (no image to upload), in the PeopleOS style.');
    }

    /** A list of short lines stored as [{text}] (chips, bullets, plan features, points). */
    public static function lines(string $name, string $label, string $add, int $max = 8): Repeater
    {
        return Repeater::make($name)->label($label)->simple(TextInput::make('text')->hiddenLabel()->required()->maxLength(160))
            ->addActionLabel($add)->defaultItems(0)->maxItems($max)->reorderableWithButtons();
    }

    /** @return list<Step> */
    public static function siteSteps(): array
    {
        return [
            Step::make('Site')->description('Whether the website is on, and its name')->schema([
                Toggle::make('enabled')->label('Public website is on')
                    ->helperText('Off: "/" goes straight to the workspace sign-in and site pages are not found. Takes effect when you publish.'),
                TextInput::make('name')->label('Site name')->required()->maxLength(40)->helperText('Shown in the header, footer and browser tab. A name ending in "OS" keeps the two-tone wordmark.'),
                TextInput::make('tagline')->label('Tagline')->maxLength(80),
            ]),
            Step::make('Contact')->description('Shown in the footer')->schema([
                TextInput::make('contact_email')->label('Sales e-mail')->email()->maxLength(190),
                TextInput::make('contact_phone')->label('Sales phone')->tel()->maxLength(40),
            ]),
        ];
    }

    /** @return list<Component|Field> */
    public static function announcementSchema(): array
    {
        return [
            Toggle::make('enabled')->label('Show the announcement bar')->live(),
            Grid::make(['default' => 1, 'md' => 3])->schema([
                TextInput::make('badge')->label('Badge')->maxLength(16)->placeholder('New'),
                TextInput::make('text')->label('Message')->maxLength(160)->columnSpan(['md' => 2])->required(fn (Get $get) => (bool) $get('enabled')),
                TextInput::make('link_label')->label('Link text')->maxLength(40),
                self::link('link_url', 'Link')->columnSpan(['md' => 2]),
            ]),
        ];
    }

    /** @return list<Field> */
    public static function headerButtonsSchema(): array
    {
        return [
            TextInput::make('sign_in_label')->label('Sign-in button text')->maxLength(30)->helperText('Blank hides the button. It always opens the workspace sign-in.'),
            TextInput::make('cta_label')->label('Main button text')->maxLength(30)->helperText('The highlighted button, e.g. Book a demo. Blank hides it.'),
            self::link('cta_url', 'Main button link'),
        ];
    }

    /** @return list<Step> */
    public static function menuSteps(): array
    {
        return [
            Step::make('Menu')->description('Its name in the header')->schema([
                TextInput::make('label')->label('Menu name')->required()->maxLength(30),
                self::link('url', 'Link')->helperText('For a menu without columns, where the name goes. For a menu with columns, an optional "see all" page for phones.'),
            ]),
            Step::make('Columns')->description('Groups of links in the panel')->schema([
                Repeater::make('columns')->label('Columns')->hiddenLabel()
                    ->schema([
                        TextInput::make('heading')->label('Column heading')->maxLength(40)->placeholder('e.g. Time & pay'),
                        Repeater::make('links')->label('Links')->schema([
                            Grid::make(['default' => 1, 'md' => 2])->schema([
                                TextInput::make('label')->label('Name')->required()->maxLength(40),
                                self::link('url', 'Link', true),
                                TextInput::make('description')->label('Short description')->maxLength(60)->columnSpan(['md' => 2]),
                                self::icon(),
                                self::hue(),
                                TextInput::make('badge')->label('Badge')->maxLength(12)->placeholder('e.g. New'),
                            ]),
                        ])->itemLabel(fn (array $state) => $state['label'] ?? null)->collapsible()->collapsed()->cloneable()->reorderableWithButtons()
                            ->addActionLabel('Add link')->defaultItems(1)->maxItems(12),
                    ])
                    ->itemLabel(fn (array $state) => filled($state['heading'] ?? null) ? $state['heading'] : 'Column')
                    ->collapsible()->reorderableWithButtons()->addActionLabel('Add column')->defaultItems(0)->maxItems(4)
                    ->helperText('No columns: the menu is a plain link. One column without a feature card: a small dropdown. Up to four columns.'),
            ]),
            Step::make('Feature card')->description('The highlighted card beside the links')->schema([
                Toggle::make('feature.enabled')->label('Show a feature card')->live(),
                Grid::make(['default' => 1, 'md' => 2])->visible(fn (Get $get) => (bool) $get('feature.enabled'))->schema([
                    TextInput::make('feature.eyebrow')->label('Small heading')->maxLength(30),
                    TextInput::make('feature.title')->label('Title')->maxLength(70)->required(fn (Get $get) => (bool) $get('feature.enabled')),
                    Textarea::make('feature.text')->label('Text')->rows(2)->maxLength(200)->columnSpan(['md' => 2]),
                    TextInput::make('feature.cta_label')->label('Link text')->maxLength(40),
                    self::link('feature.cta_url', 'Link'),
                    self::visual('feature.visual', 'none')->columnSpan(['md' => 2]),
                ]),
            ]),
            Step::make('Footer strip')->description('An optional line under the panel')->schema([
                TextInput::make('footer_text')->label('Text')->maxLength(80),
                TextInput::make('footer_link_label')->label('Link text')->maxLength(40),
                self::link('footer_link_url', 'Link'),
            ]),
        ];
    }

    /** @return list<Step> */
    public static function pageSteps(): array
    {
        return [
            Step::make('Page')->description('Title, address and whether visitors see it')->schema([
                TextInput::make('title')->label('Page title')->required()->maxLength(80),
                TextInput::make('slug')->label('Address')->prefix(url('/').'/')->maxLength(120)
                    ->helperText('Lower-case words joined by hyphens, up to three parts (e.g. product/payroll). Blank is the home page.')
                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                        try {
                            app(Website::class)->assertSlugAllowed(trim(mb_strtolower((string) $value), '/'));
                        } catch (InvalidArgumentException $e) {
                            $fail($e->getMessage());
                        }
                    }),
                Radio::make('status')->label('Visibility')->options(['published' => 'Visible to visitors (once published)', 'hidden' => 'Hidden: only operators can preview it'])->default('published')->required(),
            ]),
            Step::make('Sections')->description('The page from top to bottom')->schema([
                self::blocks(),
            ]),
            Step::make('Search and sharing')->description('How the page appears in search results and links')->schema([
                TextInput::make('seo_title')->label('Title in search results')->maxLength(70)->helperText('Blank uses the page title.'),
                Textarea::make('seo_description')->label('Description in search results')->rows(2)->maxLength(170)->helperText('About 150 characters. Blank uses the site description.'),
            ]),
        ];
    }

    public static function blocks(): Builder
    {
        $heading = [
            TextInput::make('eyebrow')->label('Small heading')->maxLength(40),
            TextInput::make('heading')->label('Heading')->maxLength(100),
            Textarea::make('text')->label('Introduction')->rows(2)->maxLength(300),
        ];

        return Builder::make('blocks')->label('Sections')->hiddenLabel()
            ->blocks([
                Block::make('hero')->label(fn (?array $state) => 'Hero'.(filled($state['title'] ?? null) ? ' · '.$state['title'] : ''))->icon('heroicon-o-sparkles')->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        TextInput::make('eyebrow')->label('Small heading')->maxLength(50),
                        Radio::make('tone')->label('Background')->options(['ink' => 'Deep ink (dark)', 'light' => 'Light'])->default('ink')->inline(),
                        TextInput::make('title')->label('Headline')->required()->maxLength(80),
                        TextInput::make('highlight')->label('Highlighted words')->maxLength(60)->helperText('Shown on a new line in the accent italic.'),
                        Textarea::make('text')->label('Text')->rows(3)->maxLength(320)->columnSpan(['md' => 2]),
                        TextInput::make('primary_label')->label('Main button text')->maxLength(30),
                        self::link('primary_url', 'Main button link'),
                        TextInput::make('secondary_label')->label('Second button text')->maxLength(30),
                        self::link('secondary_url', 'Second button link'),
                        self::visual(),
                    ]),
                    self::lines('chips', 'Proof points under the buttons', 'Add point', 4),
                ]),
                Block::make('features')->label(fn (?array $state) => 'Feature grid'.(filled($state['heading'] ?? null) ? ' · '.$state['heading'] : ''))->icon('heroicon-o-squares-2x2')->schema([
                    ...$heading,
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        Radio::make('columns')->label('Cards per row')->options(['2' => 'Two', '3' => 'Three', '4' => 'Four'])->default('3')->inline(),
                        Radio::make('tone')->label('Background')->options(['light' => 'Light', 'tint' => 'Tinted', 'ink' => 'Deep ink'])->default('light')->inline(),
                    ]),
                    Repeater::make('items')->label('Cards')->schema([
                        Grid::make(['default' => 1, 'md' => 2])->schema([
                            TextInput::make('title')->label('Title')->required()->maxLength(60),
                            self::link('url', 'Link (optional)'),
                            Textarea::make('text')->label('Text')->rows(2)->maxLength(240)->columnSpan(['md' => 2]),
                            self::icon(),
                            self::hue(),
                        ]),
                    ])->itemLabel(fn (array $state) => $state['title'] ?? null)->collapsible()->collapsed()->cloneable()->reorderableWithButtons()->addActionLabel('Add card')->maxItems(16),
                ]),
                Block::make('spotlight')->label(fn (?array $state) => 'Spotlight'.(filled($state['heading'] ?? null) ? ' · '.$state['heading'] : ''))->icon('heroicon-o-light-bulb')->schema([
                    ...$heading,
                    self::lines('bullets', 'Ticked points', 'Add point', 6),
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        self::visual(),
                        self::hue(),
                        Toggle::make('reverse')->label('Illustration on the left'),
                        TextInput::make('cta_label')->label('Link text')->maxLength(40),
                        self::link('cta_url', 'Link'),
                    ]),
                ]),
                Block::make('stats')->label('Figures')->icon('heroicon-o-chart-bar')->schema([
                    TextInput::make('heading')->label('Heading')->maxLength(80),
                    Repeater::make('items')->label('Figures')->schema([
                        Grid::make(['default' => 1, 'md' => 3])->schema([
                            TextInput::make('value')->label('Figure')->required()->maxLength(12),
                            TextInput::make('label')->label('What it counts')->maxLength(80)->columnSpan(['md' => 2]),
                        ]),
                    ])->maxItems(4)->reorderableWithButtons()->addActionLabel('Add figure')
                        ->helperText('Use only figures you can stand behind.'),
                ]),
                Block::make('steps')->label('Steps')->icon('heroicon-o-list-bullet')->schema([
                    ...$heading,
                    Repeater::make('items')->label('Steps')->schema([
                        TextInput::make('title')->label('Step')->required()->maxLength(60),
                        Textarea::make('text')->label('Text')->rows(2)->maxLength(200),
                    ])->maxItems(4)->reorderableWithButtons()->addActionLabel('Add step'),
                ]),
                Block::make('logos')->label('Customer names')->icon('heroicon-o-building-office')->schema([
                    TextInput::make('heading')->label('Heading')->maxLength(60)->placeholder('Trusted by teams at'),
                    Repeater::make('names')->label('Names')->simple(TextInput::make('name')->required()->maxLength(40))->addActionLabel('Add name')->maxItems(24)
                        ->helperText('Only list customers who have agreed to be named. Shown as a gently scrolling row of names.'),
                ]),
                Block::make('pricing')->label('Pricing plans')->icon('heroicon-o-currency-rupee')->schema([
                    ...$heading,
                    Repeater::make('plans')->label('Plans')->schema([
                        Grid::make(['default' => 1, 'md' => 3])->schema([
                            TextInput::make('name')->label('Plan')->required()->maxLength(30),
                            TextInput::make('price')->label('Price')->maxLength(20)->placeholder('₹ 99 or Custom')->helperText('Blank shows "Ask us".'),
                            TextInput::make('period')->label('Per')->maxLength(30)->placeholder('/employee/month'),
                            TextInput::make('note')->label('Note under the price')->maxLength(80)->columnSpan(['md' => 2]),
                            TextInput::make('badge')->label('Badge')->maxLength(20)->placeholder('Recommended'),
                            Textarea::make('description')->label('Who it is for')->rows(2)->maxLength(160)->columnSpan(['md' => 2]),
                            Toggle::make('highlighted')->label('Highlight this plan'),
                            TextInput::make('cta_label')->label('Button text')->maxLength(30),
                            self::link('cta_url', 'Button link')->columnSpan(['md' => 2]),
                        ]),
                        self::lines('features', 'What is included', 'Add line', 12),
                    ])->itemLabel(fn (array $state) => $state['name'] ?? null)->collapsible()->collapsed()->cloneable()->reorderableWithButtons()->maxItems(4)->addActionLabel('Add plan')
                        ->helperText('Shown on the website only; invoices use Billing → Catalogue.'),
                    TextInput::make('footnote')->label('Footnote')->maxLength(200),
                ]),
                Block::make('quote')->label('Quote')->icon('heroicon-o-chat-bubble-bottom-center-text')->schema([
                    Textarea::make('quote')->label('Quote')->required()->rows(3)->maxLength(400),
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        TextInput::make('name')->label('Who said it')->maxLength(60),
                        TextInput::make('role')->label('Role and company')->maxLength(80),
                    ]),
                ])->maxItems(4),
                Block::make('faq')->label('Questions and answers')->icon('heroicon-o-question-mark-circle')->schema([
                    TextInput::make('heading')->label('Heading')->maxLength(80),
                    Textarea::make('text')->label('Introduction')->rows(2)->maxLength(200),
                    Repeater::make('items')->label('Questions')->schema([
                        TextInput::make('question')->label('Question')->required()->maxLength(140),
                        Textarea::make('answer')->label('Answer')->required()->rows(3)->maxLength(800),
                    ])->itemLabel(fn (array $state) => $state['question'] ?? null)->collapsible()->collapsed()->reorderableWithButtons()->addActionLabel('Add question')->maxItems(20),
                ]),
                Block::make('text')->label('Text')->icon('heroicon-o-document-text')->schema([
                    TextInput::make('heading')->label('Heading')->maxLength(100),
                    MarkdownEditor::make('body')->label('Text')->maxLength(12000)
                        ->toolbarButtons(['bold', 'italic', 'link', 'heading', 'bulletList', 'orderedList', 'blockquote', 'undo', 'redo'])
                        ->helperText('Formatting is kept; any HTML typed in is shown as plain text.'),
                ]),
                Block::make('contact')->label('Contact form')->icon('heroicon-o-envelope')->schema([
                    TextInput::make('eyebrow')->label('Small heading')->maxLength(40),
                    TextInput::make('heading')->label('Heading')->required()->maxLength(100),
                    Textarea::make('text')->label('Introduction')->rows(2)->maxLength(300),
                    self::lines('points', 'Points beside the form', 'Add point', 5),
                    Toggle::make('work_email_only')->label('Work e-mail only')->default(true)->live()
                        ->helperText('Refuse personal and throwaway addresses (Gmail, Yahoo, Outlook, Hotmail, iCloud, Rediffmail, …).'),
                    Textarea::make('blocked_domains')->label('Also refuse these domains')->rows(2)->maxLength(2000)->placeholder('example-mail.com, another.in')
                        ->visible(fn (Get $get) => (bool) $get('work_email_only')),
                    Radio::make('phone')->label('Mobile number')->default('verify')->required()->options([
                        'verify' => 'Required, and verified with a code sent by SMS',
                        'optional' => 'Optional, not verified',
                        'hidden' => 'Not asked',
                    ])->helperText('Full name and work e-mail are always required. Verification needs an SMS provider on the server (PEOPLEOS_SMS_DRIVER); without one the code is only written to the log.'),
                    TextInput::make('success')->label('Thank-you message')->maxLength(160),
                ])->maxItems(1),
                Block::make('letter')->label(fn (?array $state) => 'Letter generator'.(filled($state['heading'] ?? null) ? ' · '.$state['heading'] : ''))->icon('heroicon-o-envelope')->schema([
                    TextInput::make('eyebrow')->label('Small heading')->maxLength(40),
                    TextInput::make('heading')->label('Heading')->required()->maxLength(100),
                    Textarea::make('text')->label('Introduction')->rows(2)->maxLength(300),
                    Textarea::make('template')->label('Letter template')->required()->rows(14)->maxLength(8000)
                        ->helperText('Write the letter with {placeholders} in lower case, e.g. {employee_name}. Each placeholder becomes a field for visitors; one ending in _date becomes a date picker. Visitors fill it in their own browser; nothing is sent.'),
                ])->maxItems(1),
                Block::make('calculator')->label(fn (?array $state) => 'Calculator'.(filled($state['heading'] ?? null) ? ' · '.$state['heading'] : ''))->icon('heroicon-o-calculator')->schema([
                    Select::make('kind')->label('Calculator')->options(SiteDefaults::CALCULATORS)->required()->native(false)->live(),
                    TextInput::make('eyebrow')->label('Small heading')->maxLength(40),
                    TextInput::make('heading')->label('Heading')->required()->maxLength(100),
                    Textarea::make('text')->label('Introduction')->rows(2)->maxLength(300),
                    TextInput::make('ceiling')->label('Gratuity ceiling (₹)')->numeric()->minValue(0)
                        ->helperText('The largest amount the calculator shows. Keep it in line with the current statutory limit; 0 for no limit.')
                        ->visible(fn (Get $get) => $get('kind') === 'gratuity'),
                    Textarea::make('note')->label('Assumptions shown under the result')->rows(4)->maxLength(1200),
                ])->maxItems(1),
                Block::make('cta')->label(fn (?array $state) => 'Call to action'.(filled($state['heading'] ?? null) ? ' · '.$state['heading'] : ''))->icon('heroicon-o-megaphone')->schema([
                    TextInput::make('heading')->label('Heading')->required()->maxLength(100),
                    Textarea::make('text')->label('Text')->rows(2)->maxLength(240),
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        TextInput::make('primary_label')->label('Main button text')->maxLength(30),
                        self::link('primary_url', 'Main button link'),
                        TextInput::make('secondary_label')->label('Second button text')->maxLength(30),
                        self::link('secondary_url', 'Second button link'),
                    ]),
                ]),
            ])
            ->blockNumbers(false)->collapsible()->collapsed()->cloneable()->reorderableWithButtons()
            ->addActionLabel('Add section')->blockPickerColumns(3);
    }

    /** @return list<Step> */
    public static function footerSteps(): array
    {
        $links = fn (string $name, string $add, int $max) => Repeater::make($name)->label('Links')->schema([
            Grid::make(['default' => 1, 'md' => 2])->schema([
                TextInput::make('label')->label('Name')->required()->maxLength(40),
                self::link('url', 'Link', true),
            ]),
        ])->itemLabel(fn (array $state) => $state['label'] ?? null)->reorderableWithButtons()->addActionLabel($add)->maxItems($max)->defaultItems(0);

        return [
            Step::make('About')->description('Beside the wordmark')->schema([
                Textarea::make('blurb')->label('Short description')->rows(3)->maxLength(200),
            ]),
            Step::make('Columns')->description('Groups of links')->schema([
                Repeater::make('columns')->hiddenLabel()->schema([
                    TextInput::make('heading')->label('Heading')->required()->maxLength(30),
                    $links('links', 'Add link', 10),
                ])->itemLabel(fn (array $state) => $state['heading'] ?? null)->collapsible()->reorderableWithButtons()->addActionLabel('Add column')->maxItems(5),
            ]),
            Step::make('Legal and social')->description('The bottom line')->schema([
                TextInput::make('legal')->label('Copyright line')->maxLength(120)->helperText('{year} becomes the current year.'),
                Fieldset::make('Legal links')->schema([$links('legal_links', 'Add legal link', 6)->hiddenLabel()->columnSpanFull()]),
                Repeater::make('social')->label('Social profiles')->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        Select::make('network')->label('Network')->options(['linkedin' => 'LinkedIn', 'x' => 'X', 'youtube' => 'YouTube', 'instagram' => 'Instagram', 'facebook' => 'Facebook'])->required()->native(false),
                        TextInput::make('url')->label('Profile address')->url()->required()->maxLength(300)->rule(fn () => SiteLink::rule()),
                    ]),
                ])->addActionLabel('Add profile')->maxItems(5)->defaultItems(0),
            ]),
        ];
    }

    /** @return list<Step> */
    public static function signInSteps(): array
    {
        return [
            Step::make('Welcome panel')->description('The left half of the PeopleOS sign-in page')->schema([
                TextInput::make('headline')->label('Headline')->required()->maxLength(40),
                TextInput::make('highlight')->label('Highlighted line')->maxLength(50)->helperText('Shown on its own line in gold italic.'),
                Textarea::make('text')->label('Text')->rows(2)->maxLength(200),
            ])->columns(1),
            Step::make('Notice')->description('A message above the sign-in form, e.g. planned maintenance')->schema([
                Toggle::make('notice_enabled')->label('Show a notice')->live(),
                Radio::make('notice_tone')->label('Kind')->options(['info' => 'Information', 'warning' => 'Warning'])->default('info')->inline()
                    ->visible(fn (Get $get) => (bool) $get('notice_enabled')),
                Textarea::make('notice_text')->label('Notice')->rows(2)->maxLength(240)->required(fn (Get $get) => (bool) $get('notice_enabled'))
                    ->visible(fn (Get $get) => (bool) $get('notice_enabled')),
            ]),
            Step::make('Help and apps')->description('Under the form')->schema([
                TextInput::make('help_label')->label('Help link text')->maxLength(40),
                TextInput::make('help_url')->label('Help link')->maxLength(300)->placeholder('https://… or mailto:…')->rule(fn () => SiteLink::rule())
                    ->helperText('A web address or mailto:. Blank hides the help link.'),
                Toggle::make('apps_enabled')->label('Show app store badges')->live(),
                Grid::make(['default' => 1, 'md' => 2])->visible(fn (Get $get) => (bool) $get('apps_enabled'))->schema([
                    TextInput::make('play_store_url')->label('Google Play link')->url()->maxLength(300)->rule(fn () => SiteLink::rule()),
                    TextInput::make('app_store_url')->label('App Store link')->url()->maxLength(300)->rule(fn () => SiteLink::rule()),
                ]),
            ]),
        ];
    }

    /** @return list<Field|Component> */
    public static function seoSchema(): array
    {
        return [
            TextInput::make('title')->label('Home page title in search results')->required()->maxLength(70),
            Textarea::make('description')->label('Site description')->rows(3)->maxLength(170)->helperText('Used by pages without their own description.'),
            Toggle::make('allow_indexing')->label('Allow search engines to list the website')
                ->helperText('Off (the default) keeps every page out of search results. On: the published site pages may be indexed and a sitemap is offered; the workspace (/admin and everything signed in) is never indexed either way.'),
        ];
    }

    /**
     * Form state back to the stored shape: Filament keys repeater and builder items by generated ids, the site stores lists.
     *
     * @param  array<mixed>  $state
     * @return array<mixed>
     */
    public static function clean(array $state): array
    {
        $isGenerated = fn ($key) => is_string($key) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key);
        $out = [];
        foreach ($state as $key => $value) {
            $out[$key] = is_array($value) ? self::clean($value) : (is_string($value) ? trim($value) : $value);
        }
        if ($out !== [] && collect(array_keys($out))->every($isGenerated)) {
            return array_values($out);
        }

        return $out;
    }
}
