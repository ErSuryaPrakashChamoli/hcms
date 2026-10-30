<?php

use App\Http\Controllers\AnnouncementAttachmentController;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\EmployeeProfilePrintController;
use App\Http\Controllers\ExitTenantController;
use App\Http\Controllers\Feedback360ExternalController;
use App\Http\Controllers\Form12bbController;
use App\Http\Controllers\GrievanceAttachmentController;
use App\Http\Controllers\LearningCertificateDownloadController;
use App\Http\Controllers\MyDocumentsZipController;
use App\Http\Controllers\Offboarding\ExportDownloadController;
use App\Http\Controllers\ParityFileController;
use App\Http\Controllers\SettingFileController;
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

// HROne parity MS-2: "Download all" on My profile — the signed-in person's own documents as one ZIP (no parameters).
Route::get('/me/documents/download-all', MyDocumentsZipController::class)
    ->middleware([...$protected, 'throttle:10,1'])
    ->name('me.documents.zip');

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

// HROne parity PY-13: printable Form 12BB of a tax declaration.
Route::get('/tax-declarations/{declaration}/form-12bb', Form12bbController::class)
    ->middleware($protected)
    ->whereNumber('declaration')
    ->name('tax.form-12bb');

// HROne parity WO-2: the Employee 360 as a printable page (signed link; the record's view policy; audited).
Route::get('/employees/{employee}/profile-print', EmployeeProfilePrintController::class)
    ->middleware($protected)
    ->whereNumber('employee')
    ->name('employees.profile-print');

// HROne parity: investment proofs and reimbursement bills.
Route::get('/files/{type}/{id}/{index}', ParityFileController::class)
    ->middleware($protected)
    ->whereNumber(['id', 'index'])
    ->name('parity.file');

// HROne setup parity CA-1: files held by Setup settings (e.g. the organisation's logo).
Route::get('/settings/files/{key}', SettingFileController::class)
    ->middleware($protected)
    ->where('key', '[a-z0-9_.]{1,100}')
    ->name('settings.file');

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

// HROne setup parity HP-8h: a client's 360 feedback through the signed link e-mailed to them (no sign-in). The signature
// covers the tenant and the token; the token itself is hashed, expires with the round's window and works once.
Route::match(['get', 'post'], '/feedback/360/{tenant}/{token}', Feedback360ExternalController::class)
    ->middleware(['web', 'signed', 'throttle:30,1'])
    ->whereNumber('tenant')->where('token', '[A-Za-z0-9]{40,80}')
    ->name('feedback360.external');

Route::get('/sso/{connection}/redirect', [SsoController::class, 'redirect'])->middleware('web')->name('sso.redirect');
Route::get('/sso/{connection}/callback', [SsoController::class, 'callback'])->middleware('web')->name('sso.callback');
