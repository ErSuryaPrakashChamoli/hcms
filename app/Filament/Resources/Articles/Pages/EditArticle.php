<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Support\AuditReasonField;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditArticle extends EditRecord
{
    protected static string $resource = ArticleResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->withAuditReason(AuditReasonField::extract($data))->update($data);

        return $record;
    }
}
