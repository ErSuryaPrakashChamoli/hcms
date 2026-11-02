<?php

namespace App\Filament\Resources\Users\Tables;

use App\Domain\Identity\Enums\UserKind;
use App\Domain\Identity\Enums\UserStatus;
use App\Filament\Support\BulkRecordActions;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('roles.name')->badge()->separator(','),
                TextColumn::make('status')->badge(),
                // HROne parity WO-15: a business partner's sign-in (no employee record, only the roles given) reads apart.
                TextColumn::make('kind')->label('Kind')->badge()->toggleable(),
                TextColumn::make('last_login_at')->dateTime()->sortable()->placeholder('Never'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(UserStatus::class),
                SelectFilter::make('kind')->label('Kind')->options(UserKind::class),
                SelectFilter::make('roles')->relationship('roles', 'name')->multiple()->preload(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ])
            // HROne parity WO-16: new invitations / password reset links for the users ticked (Users → Edit's own flows).
            ->toolbarActions([BulkRecordActions::selected('users', ['users.resend_invitations', 'users.send_reset_links'])]);
    }
}
