<?php

namespace App\Filament\Resources\ExpenseCategories\Pages;

use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Services\ExpenseCategories;
use App\Filament\Resources\ExpenseCategories\ExpenseCategoryForm;
use App\Filament\Resources\ExpenseCategories\ExpenseCategoryResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditExpenseCategory extends PeopleEditRecord
{
    protected static string $resource = ExpenseCategoryResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var ExpenseCategory $category */
        $category = $this->getRecord();

        return ExpenseCategoryForm::toForm($category);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $reason = AuditReasonField::extract($data);
        try {
            /** @var ExpenseCategory $record */
            return app(ExpenseCategories::class)->save($record, ExpenseCategoryForm::fromForm($data), $reason);
        } catch (ExpenseRuleViolation $e) {
            $record->refresh();
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->persistent()->send();

            throw new Halt;
        }
    }
}
