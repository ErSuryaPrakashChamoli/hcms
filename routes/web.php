<?php

use App\Http\Controllers\AnnouncementAttachmentController;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\ExitTenantController;
use App\Http\Controllers\GrievanceAttachmentController;
use App\Http\Controllers\LearningCertificateDownloadController;
use App\Http\Controllers\Offboarding\ExportDownloadController;
use App\Http\Controllers\Sso\SsoController;
use App\Http\Controllers\TicketAttachmentController;
use App\Http\Middleware\EnforceAccountSecurity;
use App\Http\Middleware\EnforceSecurityPolicy;
use App\Http\Middleware\EnsureSessionIsValid;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));

/*
| SaaS.2: protected downloads run the same session checks as the workspace, not just `auth`: the session
| password check, an active account in an accessible tenant under the current session epochs, the tenant
| security policy (IP allow-list, idle timeout) and completed account security (MFA, verified e-mail).
*/
$protected = ['web', 'auth', 'auth.session', EnsureSessionIsValid::class, ResolveTenant::class, EnforceSecurityPolicy::class, EnforceAccountSecurity::class];

Route::post('/admin/exit-tenant', ExitTenantController::class)
    ->middleware(['web', 'auth'])
    ->name('admin.exit-tenant');

Route::get('/documents/{document}/download', DocumentDownloadController::class)
    ->middleware($protected)
    ->name('documents.download');

Route::get('/learning/certificates/{certificate}/download', LearningCertificateDownloadController::class)
    ->middleware($protected)
    ->whereNumber('certificate')
    ->name('learning.certificates.download');

Route::get('/tickets/{ticket}/attachments/{comment}', TicketAttachmentController::class)
    ->middleware($protected)
    ->name('tickets.attachment');

Route::get('/grievances/{grievance}/attachments/{note}', GrievanceAttachmentController::class)
    ->middleware($protected)
    ->whereNumber(['grievance', 'note'])
    ->name('grievances.attachment');

Route::get('/announcements/{announcement}/attachment', AnnouncementAttachmentController::class)
    ->middleware($protected)
    ->whereNumber('announcement')
    ->name('announcements.attachment');

// SaaS.9: a tenant export package: the full protected chain, a signed link that expires in minutes, and the person it
// was made for (checked again with their rights in the controller).
Route::get('/offboarding/exports/{reference}/download', ExportDownloadController::class)
    ->middleware([...$protected, 'signed', 'throttle:30,1'])
    ->where('reference', '[0-9A-HJKMNP-TV-Z]{26}')
    ->name('offboarding.exports.download');

Route::get('/sso/{connection}/redirect', [SsoController::class, 'redirect'])->middleware('web')->name('sso.redirect');
Route::get('/sso/{connection}/callback', [SsoController::class, 'callback'])->middleware('web')->name('sso.callback');
