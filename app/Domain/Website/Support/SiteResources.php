<?php

namespace App\Domain\Website\Support;

/**
 * The website's Resources section (first draft): the resources hub, the HR glossary, two guides and three templates.
 * General guidance written for buyers and HR teams; it states no legal thresholds or rates, which differ by state and
 * change over time. Operators edit every word in Platform → Public website.
 */
final class SiteResources
{
    /** @return list<array<string, mixed>> */
    public static function pages(): array
    {
        return [self::hub(), self::glossary(), self::payrollChecklist(), self::choosingSoftware(), self::hrPolicy(), self::leavePolicy(), self::exitChecklist()];
    }

    private static function hub(): array
    {
        $tile = fn (string $icon, string $hue, string $title, string $text, string $url) => compact('icon', 'hue', 'title', 'text', 'url');

        return SiteDefaults::page('resources', 'Resources', [
            SiteDefaults::block('hero', [
                'eyebrow' => 'Resources', 'title' => 'Know-how for', 'highlight' => 'people who run people ops.',
                'text' => 'Plain-language guides, a glossary and templates you can adapt — written by the team that builds PeopleOS.',
                'primary_label' => '', 'primary_url' => '', 'secondary_label' => '', 'secondary_url' => '', 'visual' => 'none', 'tone' => 'light', 'chips' => [],
            ]),
            SiteDefaults::block('features', ['eyebrow' => 'Learn', 'heading' => 'Guides and glossary', 'text' => '', 'columns' => '3', 'tone' => 'light', 'items' => [
                $tile('book-open', 'iris', 'HR glossary', 'HR and payroll terms explained in plain words.', '/resources/glossary'),
                $tile('clipboard-document-check', 'gold', 'Payroll run checklist', 'What to check before, during and after every payroll run.', '/resources/payroll-checklist'),
                $tile('light-bulb', 'teal', 'Choosing HR software', 'The questions that separate a good fit from a costly switch.', '/resources/choosing-hr-software'),
            ]]),
            SiteDefaults::block('features', ['eyebrow' => 'Templates', 'heading' => 'Start from a template', 'text' => '', 'columns' => '3', 'tone' => 'tint', 'items' => [
                $tile('document-text', 'blue', 'HR policy template', 'The sections every employee handbook needs.', '/resources/hr-policy-template'),
                $tile('calendar-days', 'green', 'Leave policy template', 'Leave types, accrual, approvals and carry forward.', '/resources/leave-policy-template'),
                $tile('arrow-right-start-on-rectangle', 'slate', 'Exit checklist', 'From resignation to full and final settlement.', '/resources/exit-checklist'),
            ]]),
            SiteDefaults::block('features', ['eyebrow' => 'Free tools', 'heading' => 'Letters and calculators', 'text' => '', 'columns' => '3', 'tone' => 'light', 'items' => [
                $tile('envelope', 'iris', 'HR letter generators', 'Offer, appointment, experience, relieving letters and more.', '/tools'),
                $tile('calculator', 'gold', 'Gratuity calculator', 'Estimate gratuity from salary and service.', '/tools/gratuity-calculator'),
                $tile('currency-rupee', 'green', 'CTC to in-hand', 'See the monthly breakup and take-home.', '/tools/ctc-calculator'),
            ]]),
        ], 'Resources', 'HR guides, glossary, templates and free tools from PeopleOS.');
    }

    private static function glossary(): array
    {
        $body = implode("\n\n", array_map(fn (array $t) => "**{$t[0]}** — {$t[1]}", SiteCatalogue::glossary()));

        return SiteDefaults::page('resources/glossary', 'HR glossary', [
            SiteDefaults::block('hero', [
                'eyebrow' => 'Resources · Glossary', 'title' => 'The HR glossary,', 'highlight' => 'in plain words.',
                'text' => 'The terms HR, payroll and finance teams use every day — from CTC to F&F.',
                'primary_label' => '', 'primary_url' => '', 'secondary_label' => '', 'secondary_url' => '', 'visual' => 'none', 'tone' => 'light', 'chips' => [],
            ]),
            SiteDefaults::block('text', ['heading' => 'A to Z', 'body' => $body]),
            SiteDefaults::block('cta', ['heading' => 'See these terms at work', 'text' => 'PeopleOS shows every number with its working, from CTC to final settlement.', 'primary_label' => 'Book a demo', 'primary_url' => '/contact', 'secondary_label' => 'Free HR tools', 'secondary_url' => '/tools']),
        ], 'HR glossary', 'HR and payroll terms explained in plain words: CTC, gross and net pay, PF, ESI, professional tax, gratuity, F&F and more.');
    }

    private static function payrollChecklist(): array
    {
        return SiteDefaults::page('resources/payroll-checklist', 'Payroll run checklist', [
            SiteDefaults::block('hero', [
                'eyebrow' => 'Resources · Guide', 'title' => 'The payroll run checklist', 'highlight' => 'for a calm month end.',
                'text' => 'What to check before you calculate, while you review and after you pay — the same steps PeopleOS guides you through.',
                'primary_label' => '', 'primary_url' => '', 'secondary_label' => '', 'secondary_url' => '', 'visual' => 'none', 'tone' => 'light', 'chips' => [],
            ]),
            SiteDefaults::block('steps', ['eyebrow' => 'Three stages', 'heading' => 'Before, during and after', 'text' => '', 'items' => [
                ['title' => 'Before you calculate', 'text' => 'Lock attendance, approve pending leave and regularisations, add joiners and leavers, and confirm new bank details.'],
                ['title' => 'While you review', 'text' => 'Compare with last month, explain every large change, check statutory deductions and settlements.'],
                ['title' => 'After you pay', 'text' => 'Publish payslips, file challans and returns on time, and keep the finalised run locked.'],
            ]]),
            SiteDefaults::block('text', ['heading' => 'The full checklist', 'body' => "### Inputs\n\n- Attendance for the cycle is complete and locked; exceptions are resolved.\n- Leave, comp-off and regularisation requests for the cycle are approved or rejected.\n- Joiners have salary, bank and statutory details; leavers have their last working day.\n- One-off earnings and deductions (bonus, arrears, advances, loan instalments) are entered.\n\n### Review\n\n- Total gross and net pay are compared with the previous month and every big change is explained.\n- Employees with zero or negative pay are checked.\n- Statutory deductions look right for each employee's state and eligibility.\n- Full and final settlements match the exit records.\n\n### Close\n\n- The run is finalised and locked; any reopening is approved and recorded.\n- Payslips are published to employees.\n- Bank transfer files are generated and reconciled.\n- Statutory challans and returns are prepared and filed by their due dates."]),
            SiteDefaults::block('cta', ['heading' => 'Let PeopleOS run the checklist', 'text' => 'Pre-payroll checks catch the problems before you calculate.', 'primary_label' => 'See payroll', 'primary_url' => '/product/payroll', 'secondary_label' => 'Book a demo', 'secondary_url' => '/contact']),
        ], 'Payroll run checklist', 'A practical payroll checklist: inputs to lock, what to review and how to close each month.');
    }

    private static function choosingSoftware(): array
    {
        return SiteDefaults::page('resources/choosing-hr-software', 'Choosing HR software', [
            SiteDefaults::block('hero', [
                'eyebrow' => 'Resources · Guide', 'title' => 'Choosing HR software:', 'highlight' => 'the questions that matter.',
                'text' => 'A short buyer\'s guide to separate polished demos from software your team will rely on for years.',
                'primary_label' => '', 'primary_url' => '', 'secondary_label' => '', 'secondary_url' => '', 'visual' => 'none', 'tone' => 'light', 'chips' => [],
            ]),
            SiteDefaults::block('faq', ['heading' => 'Ask every vendor', 'text' => 'Bring these to your shortlist calls.', 'items' => [
                ['question' => 'Does it keep history with effective dates?', 'answer' => 'Transfers, promotions and pay changes should apply from a date and keep what was true before. Without it, back-dated corrections become guesswork.'],
                ['question' => 'How does payroll handle mistakes?', 'answer' => 'Ask what happens after a run is finalised. Good systems lock final runs and record any reopening with an approval.'],
                ['question' => 'Can we bring our data across safely?', 'answer' => 'Look for importers with format guides that check every row before saving, and for opening balances such as leave and year-to-date pay.'],
                ['question' => 'Who can see what?', 'answer' => 'Roles should be scoped to companies, locations or teams, and every permission change should be audited.'],
                ['question' => 'Will employees actually use it?', 'answer' => 'Try the self-service screens on a phone. If checking a payslip or applying for leave takes more than a few taps, adoption will suffer.'],
                ['question' => 'How is our data protected and returned?', 'answer' => 'Ask about isolation between customers, audit trails, two-step verification and single sign-on — and how you get a full export if you leave.'],
            ]]),
            SiteDefaults::block('cta', ['heading' => 'Put PeopleOS to the test', 'text' => 'Bring your toughest questions to a walkthrough.', 'primary_label' => 'Book a demo', 'primary_url' => '/contact', 'secondary_label' => 'See pricing', 'secondary_url' => '/pricing']),
        ], 'Choosing HR software', 'A buyer\'s guide to HR and payroll software: the questions to ask every vendor.');
    }

    private static function template(string $slug, string $title, string $intro, string $body): array
    {
        return SiteDefaults::page("resources/{$slug}", $title, [
            SiteDefaults::block('hero', [
                'eyebrow' => 'Resources · Template', 'title' => $title, 'highlight' => '', 'text' => $intro,
                'primary_label' => '', 'primary_url' => '', 'secondary_label' => '', 'secondary_url' => '', 'visual' => 'none', 'tone' => 'light', 'chips' => [],
            ]),
            SiteDefaults::block('text', ['heading' => 'Template', 'body' => $body."\n\n---\n\n*A starting point only: adapt it to your organisation and have it reviewed against the laws that apply to you.*"]),
            SiteDefaults::block('cta', ['heading' => 'Turn the policy into rules', 'text' => 'In PeopleOS, policies are configured step by step and applied automatically.', 'primary_label' => 'Book a demo', 'primary_url' => '/contact', 'secondary_label' => 'More resources', 'secondary_url' => '/resources']),
        ], $title, $intro);
    }

    private static function hrPolicy(): array
    {
        return self::template('hr-policy-template', 'HR policy template', 'The sections every employee handbook needs, ready to adapt.',
            "## 1. Purpose and scope\n\nThis policy sets out how [Company] employs, pays and supports its people. It applies to all employees of [Company] unless a section says otherwise.\n\n## 2. Equal opportunity\n\n[Company] makes employment decisions on merit and does not discriminate on any ground protected by law.\n\n## 3. Working hours and attendance\n\n- Standard working hours: [hours] a day, [days] a week.\n- Attendance is recorded by [method]; missed punches are regularised with a reason and approval.\n\n## 4. Leave\n\nLeave types, entitlements and approvals are described in the leave policy.\n\n## 5. Pay and benefits\n\n- Salary is paid monthly by [date] for the pay cycle [cycle].\n- Benefits: [list].\n\n## 6. Conduct\n\nEmployees are expected to act with integrity, respect colleagues and protect confidential information.\n\n## 7. Grievances\n\nConcerns can be raised confidentially with [role or channel]. Every grievance is acknowledged within [n] working days.\n\n## 8. Separation\n\nNotice periods, clearance and settlement are described in the exit policy.");
    }

    private static function leavePolicy(): array
    {
        return self::template('leave-policy-template', 'Leave policy template', 'Leave types, accrual, approvals and carry forward in one clear policy.',
            "## 1. Leave year\n\nThe leave year runs from [start] to [end].\n\n## 2. Leave types and entitlement\n\n| Leave type | Days a year | Accrual | Carry forward |\n|---|---|---|---|\n| Earned / privilege leave | [n] | [monthly / yearly] | Up to [n] days |\n| Casual leave | [n] | [yearly] | No |\n| Sick leave | [n] | [yearly] | [rule] |\n\n## 3. Applying for leave\n\n- Apply through PeopleOS at least [n] days in advance for planned leave.\n- Unplanned leave is applied for on the day of return at the latest.\n- Your manager approves or declines within [n] working days.\n\n## 4. Holidays and weekly offs\n\nThe holiday calendar for each location is published at the start of the year. [Sandwich rule: describe whether weekly offs and holidays between leave days count as leave.]\n\n## 5. Leave without pay\n\nLeave taken without a balance is unpaid (loss of pay).\n\n## 6. Encashment\n\nUnused [leave type] can be encashed [when], at [basis].");
    }

    private static function exitChecklist(): array
    {
        return self::template('exit-checklist', 'Exit checklist', 'Every step from resignation to full and final settlement.',
            "## When the resignation arrives\n\n- [ ] Acknowledge the resignation and confirm the last working day.\n- [ ] Agree whether any notice is waived or relaxed, and record the reason.\n- [ ] Plan the hand-over of work and knowledge.\n\n## Before the last working day\n\n- [ ] Clearance from the manager, IT, finance, admin and HR.\n- [ ] Recover laptops, ID cards and other assets.\n- [ ] Conduct the exit interview.\n- [ ] Close access to systems on the last working day.\n\n## Full and final settlement\n\n- [ ] Salary for days worked in the final month.\n- [ ] Leave encashment as per policy.\n- [ ] Gratuity, where eligible.\n- [ ] Recoveries: notice shortfall, advances, unreturned assets.\n- [ ] Issue the relieving and experience letters.");
    }
}
