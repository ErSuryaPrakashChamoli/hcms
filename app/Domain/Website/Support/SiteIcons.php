<?php

namespace App\Domain\Website\Support;

use Illuminate\Support\HtmlString;
use Throwable;

/**
 * The icons operators can pick for menu links and feature tiles: a curated Heroicons (outline) set, so a typo can
 * never break a public page and every icon reads well at 20px. Rendering an unknown name draws nothing.
 */
final class SiteIcons
{
    /** @var array<string, string> heroicon outline name => label */
    public const OPTIONS = [
        'users' => 'People',
        'user-group' => 'Team',
        'user-plus' => 'New joiner',
        'user-circle' => 'Profile',
        'identification' => 'ID card',
        'building-office-2' => 'Organisation',
        'building-storefront' => 'Store',
        'building-library' => 'Institution',
        'clock' => 'Clock',
        'calendar-days' => 'Calendar',
        'sun' => 'Holiday',
        'banknotes' => 'Money',
        'currency-rupee' => 'Rupee',
        'calculator' => 'Calculator',
        'receipt-percent' => 'Receipt',
        'scale' => 'Compliance',
        'document-text' => 'Document',
        'document-check' => 'Signed document',
        'clipboard-document-check' => 'Checklist',
        'chart-bar' => 'Bar chart',
        'presentation-chart-line' => 'Analytics',
        'chart-pie' => 'Pie chart',
        'academic-cap' => 'Learning',
        'trophy' => 'Recognition',
        'heart' => 'Engagement',
        'sparkles' => 'Highlight',
        'megaphone' => 'Announcement',
        'chat-bubble-left-right' => 'Conversation',
        'lifebuoy' => 'Helpdesk',
        'computer-desktop' => 'Asset',
        'device-phone-mobile' => 'Mobile',
        'arrow-right-start-on-rectangle' => 'Exit',
        'arrow-path-rounded-square' => 'Workflow',
        'arrows-right-left' => 'Exchange',
        'shield-check' => 'Security',
        'lock-closed' => 'Lock',
        'finger-print' => 'Identity',
        'key' => 'Key',
        'puzzle-piece' => 'Integration',
        'code-bracket' => 'API',
        'cloud-arrow-up' => 'Upload',
        'table-cells' => 'Spreadsheet',
        'globe-asia-australia' => 'Globe',
        'map-pin' => 'Location',
        'truck' => 'Logistics',
        'wrench-screwdriver' => 'Manufacturing',
        'cpu-chip' => 'Technology',
        'beaker' => 'Healthcare',
        'shopping-bag' => 'Retail',
        'briefcase' => 'Briefcase',
        'light-bulb' => 'Idea',
        'bolt' => 'Fast',
        'check-badge' => 'Verified',
        'bell-alert' => 'Alert',
        'magnifying-glass' => 'Search',
        'book-open' => 'Guide',
        'newspaper' => 'News',
        'play-circle' => 'Video',
        'phone' => 'Phone',
        'envelope' => 'E-mail',
    ];

    /** @var array<string, string> hue key => label (the PeopleOS --pos-hue-* family) */
    public const HUES = [
        'iris' => 'Iris',
        'blue' => 'Blue',
        'teal' => 'Teal',
        'green' => 'Green',
        'gold' => 'Gold',
        'coral' => 'Coral',
        'plum' => 'Plum',
        'slate' => 'Slate',
    ];

    public static function render(?string $name, string $class = 'ps-icon'): HtmlString
    {
        if (! is_string($name) || ! array_key_exists($name, self::OPTIONS)) {
            return new HtmlString('');
        }

        try {
            return new HtmlString(svg('heroicon-o-'.$name, $class, ['aria-hidden' => 'true', 'focusable' => 'false'])->toHtml());
        } catch (Throwable) {
            return new HtmlString('');
        }
    }

    public static function hue(?string $hue): string
    {
        return is_string($hue) && array_key_exists($hue, self::HUES) ? $hue : 'iris';
    }

    /** @return array<string, string> options for a picker: "users" => "People (users)" */
    public static function pickerOptions(): array
    {
        return collect(self::OPTIONS)->mapWithKeys(fn (string $label, string $name) => [$name => $label])->sort()->all();
    }
}
