<?php

namespace App\Domain\Website\Support;

/**
 * The public website's first draft: the whole site as one document. Website::draft() starts from this when nothing
 * has been saved; SiteContent fills keys a stored revision lacks (new fields added later) from here.
 *
 * Document shape (every key optional in storage):
 *  site         enabled, name, tagline, contact_email, contact_phone
 *  announcement enabled, badge, text, link_label, link_url
 *  header       sign_in_label, cta_label, cta_url, menus[] {label, url, columns[] {heading, links[] {label, description,
 *               url, icon, hue, badge}}, feature {enabled, eyebrow, title, text, cta_label, cta_url, visual}, footer_text,
 *               footer_link_label, footer_link_url}
 *  pages[]      {slug ('' = home), title, status (published|hidden), seo_title, seo_description, blocks[] {type, data}}
 *  footer       blurb, columns[] {heading, links[] {label, url}}, legal, legal_links[] {label, url}, social[] {network, url}
 *  sign_in      headline, highlight, text, notice_enabled, notice_tone, notice_text, help_label, help_url, apps_enabled,
 *               play_store_url, app_store_url
 *  seo          title, description, allow_indexing
 */
final class SiteDefaults
{
    /** Block types a page can hold, with their labels (the editor's Builder uses the same keys). */
    public const BLOCKS = [
        'hero' => 'Hero',
        'logos' => 'Customer names',
        'features' => 'Feature grid',
        'spotlight' => 'Spotlight',
        'stats' => 'Figures',
        'steps' => 'Steps',
        'pricing' => 'Pricing plans',
        'quote' => 'Quote',
        'faq' => 'Questions and answers',
        'text' => 'Text',
        'contact' => 'Contact form',
        'letter' => 'Letter generator',
        'calculator' => 'Calculator',
        'cta' => 'Call to action',
    ];

    /** Calculators a calculator block can show (worked out in the visitor's browser by resources/js/site.js). */
    public const CALCULATORS = [
        'gratuity' => 'Gratuity',
        'ctc' => 'CTC to in-hand salary',
        'leave_encashment' => 'Leave encashment',
        'notice' => 'Notice period and recovery',
    ];

    /** Illustrations a hero, spotlight or menu feature card can show (drawn in HTML, no image files). */
    public const VISUALS = [
        'workspace' => 'Workspace overview',
        'payroll' => 'Payroll run',
        'attendance' => 'Attendance calendar',
        'people' => 'People directory',
        'selfservice' => 'Phone self-service',
        'analytics' => 'Analytics chart',
        'security' => 'Security and audit',
        'import' => 'Data import',
        'assistant' => 'AI assistant',
        'orbit' => 'Orbit (abstract)',
        'none' => 'No illustration',
    ];

    /** The spotlight on a product or platform page shows a different illustration from its hero. */
    private const COMPANION_VISUAL = [
        'payroll' => 'workspace', 'attendance' => 'selfservice', 'people' => 'security', 'workspace' => 'people',
        'analytics' => 'workspace', 'selfservice' => 'attendance', 'security' => 'people', 'import' => 'security', 'orbit' => 'workspace',
        'assistant' => 'analytics',
    ];

    /** @return array<string, mixed> */
    public static function content(): array
    {
        return [
            'site' => [
                'enabled' => true,
                'name' => 'PeopleOS',
                'tagline' => 'The employee operating system',
                'contact_email' => '',
                'contact_phone' => '',
            ],
            'announcement' => [
                'enabled' => true,
                'badge' => 'New',
                'text' => 'Guided payroll runs: pre-payroll checks, one-click payslip publishing and locked final runs.',
                'link_label' => 'See how it works',
                'link_url' => '/product/payroll',
            ],
            'header' => [
                'sign_in_label' => 'Log in',
                'cta_label' => 'Book a demo',
                'cta_url' => '/contact',
                'menus' => self::menus(),
            ],
            'pages' => self::pages(),
            'footer' => self::footer(),
            'sign_in' => [
                'headline' => 'Your people.',
                'highlight' => 'One operating system.',
                'text' => 'Attendance, leave, payroll, performance and growth for every person, in one place.',
                'notice_enabled' => false,
                'notice_tone' => 'info',
                'notice_text' => '',
                'help_label' => 'Trouble signing in?',
                'help_url' => '',
                'apps_enabled' => false,
                'play_store_url' => '',
                'app_store_url' => '',
            ],
            'seo' => [
                'title' => 'PeopleOS — HR, payroll and people operations in one place',
                'description' => 'PeopleOS brings core HR, attendance, leave, payroll, compliance, performance and self-service into one connected workspace.',
                'allow_indexing' => false,
            ],
        ];
    }

    /** A menu link in the stored shape. */
    private static function menuLink(string $label, string $description, string $url, string $icon, string $hue, string $badge = ''): array
    {
        return ['label' => $label, 'description' => $description, 'url' => $url, 'icon' => $icon, 'hue' => $hue, 'badge' => $badge];
    }

    /** @return list<array<string, mixed>> */
    public static function menus(): array
    {
        $from = fn (array $items, string $prefix) => fn (string $slug) => self::menuLink($items[$slug]['title'], $items[$slug]['menu'], "/{$prefix}/{$slug}", $items[$slug]['icon'], $items[$slug]['hue'] ?? 'iris');
        $module = $from(SiteCatalogue::modules(), 'product');
        $platform = $from(SiteCatalogue::platform(), 'platform');
        $solution = $from(SiteCatalogue::solutions(), 'solutions');
        $ai = $from(SiteCatalogue::ai(), 'ai');
        $letters = SiteCatalogue::letters();
        $letter = fn (string $slug) => self::menuLink(str_replace(' generator', '', $letters[$slug]['title']), $letters[$slug]['menu'], "/tools/{$slug}", $letters[$slug]['icon'], 'iris');
        $calculators = SiteCatalogue::calculators();
        $calculator = fn (string $slug) => self::menuLink($calculators[$slug]['title'], $calculators[$slug]['menu'], "/tools/{$slug}", $calculators[$slug]['icon'], 'gold');
        $menu = fn (string $label, string $url, array $columns, array $feature = [], array $footer = ['', '', '']) => [
            'label' => $label, 'url' => $url, 'columns' => $columns, 'feature' => $feature + ['enabled' => false],
            'footer_text' => $footer[0], 'footer_link_label' => $footer[1], 'footer_link_url' => $footer[2],
        ];

        return [
            $menu('Product', '/product', [
                ['heading' => 'People', 'links' => array_map($module, ['core-hr', 'organisation', 'onboarding', 'exit', 'self-service'])],
                ['heading' => 'Time & pay', 'links' => array_map($module, ['attendance', 'leave', 'payroll', 'compliance', 'expenses'])],
                ['heading' => 'Grow & support', 'links' => array_map($module, ['performance', 'learning', 'engagement', 'helpdesk', 'assets', 'analytics'])],
                ['heading' => 'Platform', 'links' => array_map($platform, ['security', 'workflows', 'integrations', 'data-migration'])],
            ], [
                'enabled' => true, 'eyebrow' => 'Product tour', 'title' => 'One workspace for every employee moment',
                'text' => 'From the offer letter to the final settlement, every module shares one record of each person.',
                'cta_label' => 'Explore the product', 'cta_url' => '/product', 'visual' => 'workspace',
            ], ['Not sure where to start?', 'See what each plan includes', '/pricing']),
            $menu('Solutions', '/solutions', [
                ['heading' => 'By team', 'links' => array_map($solution, ['hr-teams', 'payroll-finance', 'managers', 'employees'])],
                ['heading' => 'By industry', 'links' => array_map($solution, ['manufacturing', 'it-services', 'healthcare', 'retail', 'logistics'])],
            ], [
                'enabled' => true, 'eyebrow' => 'Switching is easy', 'title' => 'From spreadsheets to live in days',
                'text' => 'Sixty-plus importers with format guides check every row before anything is saved.',
                'cta_label' => 'How migration works', 'cta_url' => '/platform/data-migration', 'visual' => 'import',
            ]),
            $menu('AI for HR', '/ai', [
                ['heading' => 'Assistants', 'links' => array_map($ai, ['assistants', 'people-search'])],
                ['heading' => 'Intelligence', 'links' => array_map($ai, ['payroll-auditor', 'trust'])],
            ], [
                'enabled' => true, 'eyebrow' => 'AI with guard-rails', 'title' => 'Answers with sources, never guesses',
                'text' => 'Assistants answer from your own records, show the facts behind each answer and never act on their own.',
                'cta_label' => 'See AI in PeopleOS', 'cta_url' => '/ai', 'visual' => 'assistant',
            ]),
            $menu('Pricing', '/pricing', []),
            $menu('Resources', '/resources', [
                ['heading' => 'Learn', 'links' => [
                    self::menuLink('HR glossary', 'HR and payroll terms, explained', '/resources/glossary', 'book-open', 'iris'),
                    self::menuLink('Payroll run checklist', 'Close every month with confidence', '/resources/payroll-checklist', 'clipboard-document-check', 'gold'),
                    self::menuLink('Choosing HR software', 'Questions to ask before you buy', '/resources/choosing-hr-software', 'light-bulb', 'teal'),
                ]],
                ['heading' => 'Templates', 'links' => [
                    self::menuLink('HR policy template', 'A starting point for your handbook', '/resources/hr-policy-template', 'document-text', 'blue'),
                    self::menuLink('Leave policy template', 'Leave types, accrual and approvals', '/resources/leave-policy-template', 'calendar-days', 'green'),
                    self::menuLink('Exit checklist', 'Clearance to full and final', '/resources/exit-checklist', 'arrow-right-start-on-rectangle', 'slate'),
                ]],
                ['heading' => 'Company', 'links' => [
                    self::menuLink('About us', 'Why we build PeopleOS', '/about', 'heart', 'coral'),
                    self::menuLink('Talk to sales', 'Book a walkthrough', '/contact', 'chat-bubble-left-right', 'iris'),
                    self::menuLink('Security & trust', 'How we protect people data', '/platform/security', 'shield-check', 'slate'),
                ]],
            ], [
                'enabled' => true, 'eyebrow' => 'Learn', 'title' => 'The HR glossary',
                'text' => 'From CTC to F&F: the terms HR, payroll and finance teams use every day, in plain words.',
                'cta_label' => 'Read the glossary', 'cta_url' => '/resources/glossary', 'visual' => 'none',
            ]),
            $menu('Tools', '/tools', [
                ['heading' => 'Letters for joining', 'links' => array_map($letter, ['offer-letter', 'appointment-letter', 'internship-offer-letter', 'confirmation-letter'])],
                ['heading' => 'Letters for change & exit', 'links' => array_map($letter, ['salary-increment-letter', 'experience-letter', 'relieving-letter', 'resignation-acceptance-letter'])],
                ['heading' => 'Calculators', 'links' => array_map($calculator, array_keys($calculators))],
            ], [
                'enabled' => true, 'eyebrow' => 'Free HR tools', 'title' => 'Letters and calculators, ready in a minute',
                'text' => 'Fill in the details and copy, print or save. Everything stays in your browser.',
                'cta_label' => 'See all tools', 'cta_url' => '/tools', 'visual' => 'none',
            ]),
        ];
    }

    /** @return array<string, mixed> */
    public static function footer(): array
    {
        $link = fn (string $label, string $url) => ['label' => $label, 'url' => $url];

        return [
            'blurb' => 'The employee operating system: core HR, time, pay and growth in one calm, connected workspace.',
            'columns' => [
                ['heading' => 'Product', 'links' => [$link('Core HR', '/product/core-hr'), $link('Attendance', '/product/attendance'), $link('Leave', '/product/leave'), $link('Payroll', '/product/payroll'), $link('Compliance & tax', '/product/compliance'), $link('Self-service', '/product/self-service'), $link('All modules', '/product')]],
                ['heading' => 'Solutions', 'links' => [$link('HR teams', '/solutions/hr-teams'), $link('Payroll & finance', '/solutions/payroll-finance'), $link('Managers', '/solutions/managers'), $link('Employees', '/solutions/employees'), $link('AI for HR', '/ai')]],
                ['heading' => 'Free tools', 'links' => [$link('Offer letter', '/tools/offer-letter'), $link('Experience letter', '/tools/experience-letter'), $link('Relieving letter', '/tools/relieving-letter'), $link('Gratuity calculator', '/tools/gratuity-calculator'), $link('CTC calculator', '/tools/ctc-calculator'), $link('All tools', '/tools')]],
                ['heading' => 'Resources', 'links' => [$link('HR glossary', '/resources/glossary'), $link('Payroll run checklist', '/resources/payroll-checklist'), $link('HR policy template', '/resources/hr-policy-template'), $link('Security & privacy', '/platform/security'), $link('Integrations & API', '/platform/integrations')]],
                ['heading' => 'Company', 'links' => [$link('About us', '/about'), $link('Pricing', '/pricing'), $link('Talk to sales', '/contact'), $link('Log in', '/admin/login')]],
            ],
            'legal' => '© {year} PeopleOS. All rights reserved.',
            'legal_links' => [],
            'social' => [],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function pages(): array
    {
        $pages = [self::home(), self::productOverview(), self::pricing(), self::about(), self::contact(),
            self::solutionsOverview(), self::aiOverview(), self::toolsOverview(), ...SiteResources::pages()];
        foreach (SiteCatalogue::ai() as $slug => $item) {
            $pages[] = self::detailPage("ai/{$slug}", $item, 'AI for HR');
        }
        foreach (SiteCatalogue::letters() as $slug => $letter) {
            $pages[] = self::letterPage($slug, $letter);
        }
        foreach (SiteCatalogue::calculators() as $slug => $calculator) {
            $pages[] = self::calculatorPage($slug, $calculator);
        }
        foreach (SiteCatalogue::modules() as $slug => $module) {
            $pages[] = self::detailPage("product/{$slug}", $module, 'Product');
        }
        foreach (SiteCatalogue::platform() as $slug => $item) {
            $pages[] = self::detailPage("platform/{$slug}", $item, 'Platform');
        }
        foreach (SiteCatalogue::solutions() as $slug => $solution) {
            $pages[] = self::solutionPage($slug, $solution);
        }

        return $pages;
    }

    /** @param array<string, mixed> $data */
    public static function block(string $type, array $data): array
    {
        return ['type' => $type, 'data' => $data];
    }

    /** @return array<string, mixed> */
    public static function page(string $slug, string $title, array $blocks, string $seoTitle = '', string $seoDescription = ''): array
    {
        return ['slug' => $slug, 'title' => $title, 'status' => 'published', 'seo_title' => $seoTitle, 'seo_description' => $seoDescription, 'blocks' => $blocks];
    }

    private static function home(): array
    {
        $modules = SiteCatalogue::modules();
        $tile = fn (string $slug) => ['icon' => $modules[$slug]['icon'], 'hue' => $modules[$slug]['hue'], 'title' => $modules[$slug]['title'], 'text' => $modules[$slug]['intro'], 'url' => "/product/{$slug}"];

        return self::page('', 'Home', [
            self::block('hero', [
                'eyebrow' => 'HR · Payroll · People operations',
                'title' => 'The operating system for',
                'highlight' => 'everyone at work.',
                'text' => 'PeopleOS brings hiring to exit, attendance, leave, payroll and compliance into one calm, connected workspace — so HR runs on facts, not follow-ups.',
                'primary_label' => 'Book a demo', 'primary_url' => '/contact',
                'secondary_label' => 'Explore the product', 'secondary_url' => '/product',
                'visual' => 'workspace', 'tone' => 'ink',
                'chips' => [['text' => 'Payroll that closes on time'], ['text' => 'Compliance reports for 36 states & UTs'], ['text' => 'Self-service on any device']],
            ]),
            self::block('features', [
                'eyebrow' => 'One workspace', 'heading' => 'Every HR process, connected end to end',
                'text' => 'Each module shares one record of each person, so a change in one place is right everywhere else.',
                'columns' => '4', 'tone' => 'light',
                'items' => array_map($tile, ['core-hr', 'attendance', 'leave', 'payroll', 'compliance', 'performance', 'self-service', 'analytics']),
            ]),
            self::block('spotlight', [
                'eyebrow' => 'Payroll', 'heading' => 'A payroll run you can follow step by step',
                'text' => 'Pre-payroll checks catch the problems first. Then calculate, review, finalise and publish payslips — and a finalised run stays locked.',
                'bullets' => [['text' => 'Joiners, leavers and unapproved days flagged before you run'], ['text' => 'Salary structures built in plain English'], ['text' => 'Registers, challans and returns from the same numbers']],
                'visual' => 'payroll', 'reverse' => false, 'hue' => 'iris', 'cta_label' => 'See payroll', 'cta_url' => '/product/payroll',
            ]),
            self::block('spotlight', [
                'eyebrow' => 'Time & attendance', 'heading' => 'Attendance that explains every day',
                'text' => 'Shifts, web and biometric punches, regularisation and a calendar where every status has a reason.',
                'bullets' => [['text' => 'Exceptions in one queue before the month closes'], ['text' => 'State-wise holiday calendars from the holiday library'], ['text' => 'Paid days ready for payroll']],
                'visual' => 'attendance', 'reverse' => true, 'hue' => 'green', 'cta_label' => 'See attendance', 'cta_url' => '/product/attendance',
            ]),
            self::block('spotlight', [
                'eyebrow' => 'Self-service', 'heading' => 'HR in everyone\'s pocket',
                'text' => 'Payslips, leave, check-in, requests and tax declarations — answered in seconds from any phone, no app install needed.',
                'bullets' => [['text' => 'Guided investment declarations'], ['text' => 'Approvals for managers on the go'], ['text' => 'A home page that shows what needs attention']],
                'visual' => 'selfservice', 'reverse' => false, 'hue' => 'teal', 'cta_label' => 'See self-service', 'cta_url' => '/product/self-service',
            ]),
            self::block('stats', [
                'heading' => 'Depth where it counts',
                'items' => [
                    ['value' => '36', 'label' => 'states and union territories in the compliance reports centre'],
                    ['value' => '149', 'label' => 'ready-made report templates'],
                    ['value' => '60+', 'label' => 'data importers with format guides'],
                    ['value' => '5', 'label' => 'steps to a complete salary structure'],
                ],
            ]),
            self::block('steps', [
                'eyebrow' => 'Getting started', 'heading' => 'Live in weeks, not quarters',
                'text' => 'A clear path from your spreadsheets to your first payroll.',
                'items' => [
                    ['title' => 'Bring your data', 'text' => 'Import people, salaries, balances and history with checked templates.'],
                    ['title' => 'Set your policies', 'text' => 'Leave, attendance and payroll rules configured with step-by-step wizards.'],
                    ['title' => 'Run with confidence', 'text' => 'Your first guided payroll run, with every check done for you.'],
                ],
            ]),
            self::block('features', [
                'eyebrow' => 'Security', 'heading' => 'Built to be trusted with people data',
                'text' => 'Controls that hold up to your auditors and your employees\' expectations.',
                'columns' => '3', 'tone' => 'ink',
                'items' => [
                    ['icon' => 'lock-closed', 'hue' => 'iris', 'title' => 'Isolation by default', 'text' => 'Each organisation\'s data is fenced in at the data layer.', 'url' => '/platform/security'],
                    ['icon' => 'document-check', 'hue' => 'iris', 'title' => 'Tamper-evident audit', 'text' => 'Every change chained to the one before it.', 'url' => '/platform/security'],
                    ['icon' => 'key', 'hue' => 'iris', 'title' => 'MFA and single sign-on', 'text' => 'Two-step verification and your identity provider.', 'url' => '/platform/security'],
                ],
            ]),
            self::block('faq', [
                'heading' => 'Questions, answered',
                'items' => [
                    ['question' => 'Who is PeopleOS for?', 'answer' => 'Organisations that want HR, payroll and people operations in one place — from a single company to groups with several legal entities and locations.'],
                    ['question' => 'Can we bring our existing data?', 'answer' => 'Yes. Sixty-plus importers with format guides bring across people, salaries, leave balances, attendance and history, and every row is checked before it is saved.'],
                    ['question' => 'Does it work on phones?', 'answer' => 'Yes. Employee self-service and manager approvals are designed for phones first and run in any modern browser.'],
                    ['question' => 'How is our data protected?', 'answer' => 'Organisation data is isolated by default, every change is recorded in a tamper-evident audit trail, and sign-in supports two-step verification and single sign-on.'],
                    ['question' => 'How long does it take to go live?', 'answer' => 'It depends on your size and policies; most of the work is importing data and configuring policies, both of which are guided.'],
                ],
            ]),
            self::block('cta', [
                'heading' => 'See PeopleOS with your own policies',
                'text' => 'A 30-minute walkthrough shaped around how your organisation works.',
                'primary_label' => 'Book a demo', 'primary_url' => '/contact',
                'secondary_label' => 'See pricing', 'secondary_url' => '/pricing',
            ]),
        ], '', '');
    }

    private static function productOverview(): array
    {
        $tiles = [];
        foreach (SiteCatalogue::modules() as $slug => $m) {
            $tiles[] = ['icon' => $m['icon'], 'hue' => $m['hue'], 'title' => $m['title'], 'text' => $m['menu'], 'url' => "/product/{$slug}"];
        }

        return self::page('product', 'Product', [
            self::block('hero', [
                'eyebrow' => 'Product', 'title' => 'Sixteen modules.', 'highlight' => 'One record of each person.',
                'text' => 'Every PeopleOS module works from the same employee record, the same policies and the same audit trail — so nothing has to be typed twice.',
                'primary_label' => 'Book a demo', 'primary_url' => '/contact', 'secondary_label' => 'See pricing', 'secondary_url' => '/pricing',
                'visual' => 'orbit', 'tone' => 'ink', 'chips' => [],
            ]),
            self::block('features', [
                'eyebrow' => 'Modules', 'heading' => 'Everything HR, in one workspace', 'text' => '',
                'columns' => '4', 'tone' => 'light', 'items' => $tiles,
            ]),
            self::block('cta', [
                'heading' => 'Pick the modules you need today', 'text' => 'Start with what matters most and switch on more as you grow.',
                'primary_label' => 'Talk to sales', 'primary_url' => '/contact', 'secondary_label' => 'See pricing', 'secondary_url' => '/pricing',
            ]),
        ], 'Product', 'All PeopleOS modules: core HR, attendance, leave, payroll, compliance, performance, learning, engagement, helpdesk, assets, self-service and analytics.');
    }

    private static function pricing(): array
    {
        return self::page('pricing', 'Pricing', [
            self::block('hero', [
                'eyebrow' => 'Pricing', 'title' => 'Simple plans,', 'highlight' => 'priced per employee.',
                'text' => 'Choose a plan, add what you need, and let us recommend the right fit for your organisation.',
                'primary_label' => '', 'primary_url' => '', 'secondary_label' => '', 'secondary_url' => '',
                'visual' => 'none', 'tone' => 'light', 'chips' => [],
            ]),
            self::block('pricing', [
                'eyebrow' => '', 'heading' => 'Plans', 'text' => '',
                'plans' => [
                    ['name' => 'Essentials', 'price' => '', 'period' => '/month', 'note' => 'Pricing on request', 'description' => 'Core HR, time and leave for growing teams.', 'highlighted' => false, 'badge' => '',
                        'features' => [['text' => 'Core HR and organisation'], ['text' => 'Attendance and leave'], ['text' => 'Employee self-service'], ['text' => 'Holiday library'], ['text' => 'Data import with format guides']],
                        'cta_label' => 'Get a quote', 'cta_url' => '/contact'],
                    ['name' => 'Professional', 'price' => '', 'period' => '/month', 'note' => 'Pricing on request', 'description' => 'Everything to run payroll and compliance.', 'highlighted' => true, 'badge' => 'Recommended',
                        'features' => [['text' => 'Everything in Essentials'], ['text' => 'Guided payroll runs'], ['text' => 'Compliance reports centre'], ['text' => 'Expenses and advances'], ['text' => 'Investment declarations'], ['text' => 'Letter studio and report library']],
                        'cta_label' => 'Get a quote', 'cta_url' => '/contact'],
                    ['name' => 'Enterprise', 'price' => 'Custom', 'period' => '', 'note' => 'For larger and multi-entity organisations', 'description' => 'The full suite with enterprise controls.', 'highlighted' => false, 'badge' => '',
                        'features' => [['text' => 'Everything in Professional'], ['text' => 'Performance, learning and engagement'], ['text' => 'Single sign-on, SCIM and API'], ['text' => 'Workflows and delegation'], ['text' => 'Network rules and audit exports']],
                        'cta_label' => 'Talk to sales', 'cta_url' => '/contact'],
                ],
                'footnote' => 'Prices exclude applicable taxes. Plans and add-ons are confirmed in your order form.',
            ]),
            self::block('faq', [
                'heading' => 'Pricing questions',
                'items' => [
                    ['question' => 'How is pricing worked out?', 'answer' => 'Plans are priced per active employee per month, with the modules in the plan included.'],
                    ['question' => 'Can we change plans later?', 'answer' => 'Yes. You can move to a larger plan or add modules as your needs grow.'],
                    ['question' => 'Is there a setup fee?', 'answer' => 'Talk to us — it depends on how much data and configuration you would like help with.'],
                ],
            ]),
            self::block('cta', [
                'heading' => 'Not sure which plan fits?', 'text' => 'Tell us about your organisation and we will recommend one.',
                'primary_label' => 'Talk to sales', 'primary_url' => '/contact', 'secondary_label' => '', 'secondary_url' => '',
            ]),
        ], 'Pricing', 'PeopleOS plans and pricing.');
    }

    private static function about(): array
    {
        return self::page('about', 'About us', [
            self::block('hero', [
                'eyebrow' => 'About', 'title' => 'We build the calm', 'highlight' => 'behind great workplaces.',
                'text' => 'PeopleOS exists so that the people who look after people can spend their time on people.',
                'primary_label' => 'Talk to us', 'primary_url' => '/contact', 'secondary_label' => '', 'secondary_url' => '',
                'visual' => 'orbit', 'tone' => 'ink', 'chips' => [],
            ]),
            self::block('text', [
                'heading' => 'Why PeopleOS',
                'body' => "HR teams spend too much of their week reconciling spreadsheets, chasing approvals and answering the same questions. We think that time belongs to people.\n\nPeopleOS is one operating system for every employee moment: one record of each person, policies configured once, and every change recorded. It is designed to be clear on a phone, thorough for an auditor and calm for everyone in between.",
            ]),
            self::block('cta', [
                'heading' => 'Let\'s talk', 'text' => 'We would love to hear how your organisation works.',
                'primary_label' => 'Talk to sales', 'primary_url' => '/contact', 'secondary_label' => '', 'secondary_url' => '',
            ]),
        ], 'About us', 'Why we build PeopleOS.');
    }

    private static function contact(): array
    {
        return self::page('contact', 'Talk to sales', [
            self::block('contact', [
                'eyebrow' => 'Talk to sales', 'heading' => 'See PeopleOS with your own policies',
                'text' => 'Tell us a little about your organisation and we will set up a walkthrough around how you work.',
                'points' => [['text' => 'A 30-minute walkthrough shaped around your policies'], ['text' => 'Answers on migration, pricing and timelines'], ['text' => 'No obligation, no spam']],
                'success' => 'Thank you — we will be in touch within one working day.',
                'phone' => 'verify',
                'work_email_only' => true,
            ]),
        ], 'Talk to sales', 'Book a PeopleOS walkthrough.');
    }

    /** A product or platform page: hero, features, spotlight and a call to action. */
    private static function detailPage(string $slug, array $item, string $eyebrow): array
    {
        $features = array_map(fn (array $f) => ['icon' => $f[0], 'hue' => $item['hue'], 'title' => $f[1], 'text' => $f[2], 'url' => ''], $item['features']);
        [$spotHeading, $spotText, $spotBullets] = $item['spotlight'];

        return self::page($slug, $item['title'], [
            self::block('hero', [
                'eyebrow' => "{$eyebrow} · {$item['title']}", 'title' => $item['headline'], 'highlight' => $item['highlight'], 'text' => $item['intro'],
                'primary_label' => 'Book a demo', 'primary_url' => '/contact', 'secondary_label' => 'See pricing', 'secondary_url' => '/pricing',
                'visual' => $item['visual'], 'tone' => 'ink', 'chips' => [],
            ]),
            self::block('features', [
                'eyebrow' => $item['title'], 'heading' => 'What you get', 'text' => '', 'columns' => '3', 'tone' => 'light', 'items' => $features,
            ]),
            self::block('spotlight', [
                'eyebrow' => 'Why it matters', 'heading' => $spotHeading, 'text' => $spotText,
                'bullets' => array_map(fn (string $b) => ['text' => $b], $spotBullets),
                'visual' => self::COMPANION_VISUAL[$item['visual']] ?? 'workspace', 'reverse' => true, 'hue' => $item['hue'], 'cta_label' => '', 'cta_url' => '',
            ]),
            self::block('cta', [
                'heading' => "See {$item['title']} in action", 'text' => 'A walkthrough shaped around your own policies.',
                'primary_label' => 'Book a demo', 'primary_url' => '/contact', 'secondary_label' => 'Explore all modules', 'secondary_url' => '/product',
            ]),
        ], $item['title'], $item['intro']);
    }

    private static function solutionsOverview(): array
    {
        $tiles = [];
        foreach (SiteCatalogue::solutions() as $slug => $item) {
            $tiles[] = ['icon' => $item['icon'], 'hue' => $item['hue'], 'title' => $item['title'], 'text' => $item['intro'], 'url' => "/solutions/{$slug}"];
        }

        return self::page('solutions', 'Solutions', [
            self::block('hero', [
                'eyebrow' => 'Solutions', 'title' => 'Built for every team,', 'highlight' => 'shaped for your industry.',
                'text' => 'HR, payroll, managers and employees each get the screens they need — on shop floors, in stores, on the road and in offices.',
                'primary_label' => 'Book a demo', 'primary_url' => '/contact', 'secondary_label' => 'See pricing', 'secondary_url' => '/pricing',
                'visual' => 'people', 'tone' => 'ink', 'chips' => [],
            ]),
            self::block('features', ['eyebrow' => 'By team and industry', 'heading' => 'Find your starting point', 'text' => '', 'columns' => '3', 'tone' => 'light', 'items' => $tiles]),
            self::block('cta', ['heading' => 'See it with your own policies', 'text' => 'A walkthrough shaped around how your organisation works.', 'primary_label' => 'Book a demo', 'primary_url' => '/contact', 'secondary_label' => '', 'secondary_url' => '']),
        ], 'Solutions', 'PeopleOS for HR teams, payroll and finance, managers and employees, across industries.');
    }

    private static function aiOverview(): array
    {
        $tiles = [];
        foreach (SiteCatalogue::ai() as $slug => $item) {
            $tiles[] = ['icon' => $item['icon'], 'hue' => $item['hue'], 'title' => $item['title'], 'text' => $item['intro'], 'url' => "/ai/{$slug}"];
        }

        return self::page('ai', 'AI for HR', [
            self::block('hero', [
                'eyebrow' => 'AI for HR', 'title' => 'AI that knows your HR,', 'highlight' => 'and shows its working.',
                'text' => 'Assistants for every role, a payroll auditor that explains each finding, and plain-English search — inside a data boundary you control.',
                'primary_label' => 'Book a demo', 'primary_url' => '/contact', 'secondary_label' => 'How we keep data safe', 'secondary_url' => '/ai/trust',
                'visual' => 'assistant', 'tone' => 'ink', 'chips' => [['text' => 'Answers with sources'], ['text' => 'Never acts on its own'], ['text' => 'Secrets never leave']],
            ]),
            self::block('features', ['eyebrow' => 'What it does', 'heading' => 'Intelligence across PeopleOS', 'text' => '', 'columns' => '2', 'tone' => 'light', 'items' => $tiles]),
            self::block('steps', [
                'eyebrow' => 'How an answer is made', 'heading' => 'Grounded, checked, explained', 'text' => '',
                'items' => [
                    ['title' => 'Your records first', 'text' => 'The assistant gathers the facts the person is allowed to see.'],
                    ['title' => 'Classified', 'text' => 'Every fact is checked against your data boundary before anything leaves PeopleOS.'],
                    ['title' => 'Answered with sources', 'text' => 'The answer lists the facts it used and suggests next steps — a person decides.'],
                ],
            ]),
            self::block('cta', ['heading' => 'See the assistants on your own data', 'text' => 'A walkthrough shaped around your policies.', 'primary_label' => 'Book a demo', 'primary_url' => '/contact', 'secondary_label' => '', 'secondary_url' => '']),
        ], 'AI for HR', 'PeopleOS AI: assistants with sources, a payroll auditor and plain-English search, inside a data boundary you control.');
    }

    private static function toolsOverview(): array
    {
        $letters = [];
        foreach (SiteCatalogue::letters() as $slug => $letter) {
            $letters[] = ['icon' => $letter['icon'], 'hue' => 'iris', 'title' => str_replace(' generator', '', $letter['title']), 'text' => $letter['intro'], 'url' => "/tools/{$slug}"];
        }
        $calculators = [];
        foreach (SiteCatalogue::calculators() as $slug => $calculator) {
            $calculators[] = ['icon' => $calculator['icon'], 'hue' => 'gold', 'title' => $calculator['title'], 'text' => $calculator['intro'], 'url' => "/tools/{$slug}"];
        }

        return self::page('tools', 'Free HR tools', [
            self::block('hero', [
                'eyebrow' => 'Free HR tools', 'title' => 'Letters and calculators,', 'highlight' => 'ready in a minute.',
                'text' => 'Generate common HR letters and work out gratuity, take-home pay, leave encashment and notice periods. Free, with nothing to sign up for — and nothing you type leaves your browser.',
                'primary_label' => '', 'primary_url' => '', 'secondary_label' => '', 'secondary_url' => '',
                'visual' => 'none', 'tone' => 'light', 'chips' => [],
            ]),
            self::block('features', ['eyebrow' => 'Letter generators', 'heading' => 'HR letters', 'text' => 'Fill in the details, then copy, print or save as PDF.', 'columns' => '4', 'tone' => 'light', 'items' => $letters]),
            self::block('features', ['eyebrow' => 'Calculators', 'heading' => 'Pay calculators', 'text' => 'Quick estimates with the working shown.', 'columns' => '4', 'tone' => 'tint', 'items' => $calculators]),
            self::block('cta', ['heading' => 'Letters for every employee, automatically', 'text' => 'PeopleOS fills letters from employee records, routes them for approval and keeps a copy on the profile.', 'primary_label' => 'See core HR', 'primary_url' => '/product/core-hr', 'secondary_label' => 'Book a demo', 'secondary_url' => '/contact']),
        ], 'Free HR tools', 'Free HR letter generators and pay calculators: offer, appointment, experience and relieving letters; gratuity, CTC, leave encashment and notice period calculators.');
    }

    /** @param array<string, string> $letter */
    private static function letterPage(string $slug, array $letter): array
    {
        return self::page("tools/{$slug}", $letter['title'], [
            self::block('letter', [
                'eyebrow' => 'Free HR tool', 'heading' => $letter['title'], 'text' => $letter['intro'], 'template' => $letter['template'],
            ]),
            self::block('cta', [
                'heading' => 'Generate letters for everyone at once', 'text' => 'In PeopleOS, letters fill themselves from employee records, go for approval and are filed on the profile.',
                'primary_label' => 'See the letter studio', 'primary_url' => '/product/core-hr', 'secondary_label' => 'All free tools', 'secondary_url' => '/tools',
            ]),
        ], $letter['title'], $letter['intro']);
    }

    /** @param array<string, string> $calculator */
    private static function calculatorPage(string $slug, array $calculator): array
    {
        return self::page("tools/{$slug}", $calculator['title'], [
            self::block('calculator', [
                'kind' => $calculator['kind'], 'eyebrow' => 'Free HR tool', 'heading' => $calculator['title'], 'text' => $calculator['intro'],
                'note' => $calculator['note'], 'ceiling' => $calculator['kind'] === 'gratuity' ? '2000000' : '',
            ]),
            self::block('cta', [
                'heading' => 'Let payroll do the maths', 'text' => 'PeopleOS works these out from real records inside every payroll run and settlement.',
                'primary_label' => 'See payroll', 'primary_url' => '/product/payroll', 'secondary_label' => 'All free tools', 'secondary_url' => '/tools',
            ]),
        ], $calculator['title'], $calculator['intro']);
    }

    private static function solutionPage(string $slug, array $solution): array
    {
        $modules = SiteCatalogue::modules();
        $platform = SiteCatalogue::platform();
        $items = [];
        foreach ($solution['links'] as $link) {
            $item = $modules[$link] ?? $platform[$link] ?? null;
            if ($item !== null) {
                $items[] = ['icon' => $item['icon'], 'hue' => $item['hue'], 'title' => $item['title'], 'text' => $item['intro'], 'url' => (isset($modules[$link]) ? '/product/' : '/platform/').$link];
            }
        }
        $eyebrow = $solution['group'] === 'team' ? 'For '.mb_strtolower($solution['title']) : $solution['title'];

        return self::page("solutions/{$slug}", $solution['title'], [
            self::block('hero', [
                'eyebrow' => $eyebrow, 'title' => $solution['headline'], 'highlight' => $solution['highlight'], 'text' => $solution['intro'],
                'primary_label' => 'Book a demo', 'primary_url' => '/contact', 'secondary_label' => 'See pricing', 'secondary_url' => '/pricing',
                'visual' => 'orbit', 'tone' => 'ink', 'chips' => [],
            ]),
            self::block('features', [
                'eyebrow' => 'Where PeopleOS helps', 'heading' => 'The modules that matter most', 'text' => '',
                'columns' => count($items) % 3 === 0 ? '3' : '2', 'tone' => 'light', 'items' => $items,
            ]),
            self::block('cta', [
                'heading' => 'See how it fits your organisation', 'text' => 'A walkthrough shaped around your own policies.',
                'primary_label' => 'Book a demo', 'primary_url' => '/contact', 'secondary_label' => '', 'secondary_url' => '',
            ]),
        ], $solution['title'], $solution['intro']);
    }
}
