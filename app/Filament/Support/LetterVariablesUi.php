<?php

namespace App\Filament\Support;

use App\Domain\Letters\Services\Letters;
use App\Domain\Letters\Support\DocxLetter;
use App\Domain\Letters\Support\LetterVariables;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Action;

/**
 * The letter variables guide beside "New letter template": every {{ variable }} with its meaning and an example, to
 * view (search, click to copy) or download as a page or a spreadsheet list, plus a starter Word template. Generated
 * from LetterVariables, so it lists exactly what letters are filled with, including this tenant's salary components
 * and custom fields.
 */
final class LetterVariablesUi
{
    public static function guideAction(string $name = 'letterVariables'): Action
    {
        return Action::make($name)->label('Variables')->icon('heroicon-m-code-bracket')->color('gray')
            ->modalHeading('Letter variables')->modalDescription('Put a variable anywhere in a letter, its subject or your Word file; each letter is filled in with the employee’s own details.')
            ->modalWidth('5xl')->slideOver()
            ->modalSubmitAction(false)->modalCancelActionLabel('Close')
            ->modalContent(fn () => view('filament.letters.variables-guide', ['groups' => LetterVariables::catalogue()]))
            ->extraModalFooterActions([
                Action::make('downloadVariablesGuide')->label('Download guide')->icon('heroicon-m-arrow-down-tray')->color('gray')
                    ->action(fn () => response()->streamDownload(fn () => print (self::guideDocument()), 'letter-variables.html', ['Content-Type' => 'text/html'])),
                Action::make('downloadVariablesList')->label('Download list (CSV)')->icon('heroicon-m-table-cells')->color('gray')
                    ->action(fn () => response()->streamDownload(fn () => print (self::csv()), 'letter-variables.csv', ['Content-Type' => 'text/csv'])),
                self::sampleAction(),
            ]);
    }

    public static function sampleAction(string $name = 'downloadSampleWordTemplate'): Action
    {
        return Action::make($name)->label('Sample Word template')->icon('heroicon-m-document-arrow-down')->color('gray')
            ->action(fn () => response()->streamDownload(fn () => print (DocxLetter::sample()), 'peopleos-letter-template.docx', ['Content-Type' => Letters::DOCX_MIME]));
    }

    /** The guide as a standalone page to keep, share or print. */
    public static function guideDocument(): string
    {
        return view('filament.letters.variables-guide-document', ['groups' => LetterVariables::catalogue(), 'tenant' => app(TenantContext::class)->current()?->name])->render();
    }

    /** Every variable as a spreadsheet row: group, variable, meaning, example. */
    public static function csv(): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Group', 'Variable', 'Meaning', 'Example'], escape: '');
        // Custom field labels are typed by users: a cell starting with = + - @ would run as a spreadsheet formula.
        $cell = fn (string $value) => preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
        foreach (LetterVariables::catalogue() as $group) {
            foreach ($group['variables'] as $variable => [$meaning, $example]) {
                fputcsv($out, array_map($cell, [$group['title'], '{{ '.$variable.' }}', $meaning, $example]), escape: '');
            }
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
