<?php

use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\ExitTenantController;
use App\Http\Controllers\Sso\SsoController;
use App\Http\Controllers\TicketAttachmentController;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));

Route::post('/admin/exit-tenant', ExitTenantController::class)
    ->middleware(['web', 'auth'])
    ->name('admin.exit-tenant');

Route::get('/documents/{document}/download', DocumentDownloadController::class)
    ->middleware(['web', 'auth', ResolveTenant::class])
    ->name('documents.download');

Route::get('/tickets/{ticket}/attachments/{comment}', TicketAttachmentController::class)
    ->middleware(['web', 'auth', ResolveTenant::class])
    ->name('tickets.attachment');

Route::get('/sso/{connection}/redirect', [SsoController::class, 'redirect'])->middleware('web')->name('sso.redirect');
Route::get('/sso/{connection}/callback', [SsoController::class, 'callback'])->middleware('web')->name('sso.callback');
