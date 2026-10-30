<?php

namespace App\Filament\Resources\ExpenseCategories\Pages;

use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Services\ExpenseCategories;
use App\Filament\Resources\ExpenseCategories\ExpenseCategoryForm;
use App\Filament\Resources\ExpenseCategories\ExpenseCategoryResource;
use App\Filament\Support\Pages\PeopleCreateRecord;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateExpenseCategory extends PeopleCreateRecord
{
    protected static string $resource = ExpenseCategoryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ExpenseCategories::class)->save(null, ExpenseCategoryForm::fromForm($data));
        } catch (ExpenseRuleViolation $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->persistent()->send();

            throw new Halt;
        }
    }
}
