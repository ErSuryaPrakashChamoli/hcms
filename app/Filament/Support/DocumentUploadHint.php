<?php

namespace App\Filament\Support;

use App\Domain\Documents\Models\DocumentType;
use App\Http\Controllers\ParityFileController;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;

/** HROne setup parity CA-4: an upload dialog shows the chosen document type's instructions and a link to its sample. */
final class DocumentUploadHint
{
    public static function make(string $typeField = 'document_type_id'): Text
    {
        $type = fn (Get $get): ?DocumentType => filled($get($typeField)) ? DocumentType::query()->find($get($typeField)) : null;

        return Text::make(function (Get $get) use ($type) {
            $chosen = $type($get);
            $html = e((string) $chosen?->sample_instructions);
            if (filled($chosen?->sample_path)) {
                $html .= ($html === '' ? '' : ' ').'<a class="underline" target="_blank" rel="noopener" href="'.e(ParityFileController::url('document-type-sample', $chosen, 0)).'">Open the sample</a>';
            }

            return new HtmlString($html);
        })->visible(fn (Get $get) => ($chosen = $type($get)) !== null && (filled($chosen->sample_instructions) || filled($chosen->sample_path)));
    }
}
