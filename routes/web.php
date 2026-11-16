<?php

use App\Domain\Website\Services\Website;
use App\Http\Controllers\AnnouncementAttachmentController;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\EmployeeDocumentDownloadController;
use App\Http\Controllers\EmployeeProfilePrintController;
use App\Http\Controllers\ExitTenantController;
use App\Http\Controllers\ExpenseDocumentDownloadController;
use App\Http\Controllers\ExpenseReportPrintController;
use App\Http\Controllers\Feedback360ExternalController;
use App\Http\Controllers\Form12bbController;
use App\Http\Controllers\GrievanceAttachmentController;
use App\Http\Controllers\LearningCertificateDownloadController;
use App\Http\Controllers\LetterDownloadController;
use App\Http\Controllers\MyDocumentsZipController;
use App\Http\Controllers\Offboarding\ExportDownloadController;
use App\Http\Controllers\ParityFileController;
use App\Http\Controllers\SettingFileController;
use App\Http\Controllers\SignIn\BrandImageController;
use App\Http\Controllers\SignIn\SignInLinkController;
use App\Http\Controllers\Sso\SsoController;
use App\Http\Controllers\TicketAttachmentController;
use App\Http\Controllers\Website\SiteController;
use App\Http\Middleware\EnforceAccountSecurity;
use App\Http\Middleware\EnforceSecurityPolicy;
use App\Http\Middleware\EnsureSessionIsValid;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

// Public website (Platform → Public website): the home page once an operator has published the site; until then "/" goes
// to the workspace sign-in as before. The site's other routes are at the end of this file.
Route::get('/', [SiteController::class, 'home'])->middleware('web')->name('website.home');

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

// HROne parity WO-13: a prepared bulk download of issued letters (signed link; only the person who asked for it; audited).
Route::get('/letters/downloads/{download}', LetterDownloadController::class)
    ->middleware($protected)
    ->whereNumber('download')
    ->name('letters.downloads.zip');

// HROne parity WO-4: a prepared "Download all documents" zip of one employee (signed link; only the person who asked; audited).
Route::get('/employees/documents/downloads/{download}', EmployeeDocumentDownloadController::class)
    ->middleware($protected)
    ->whereNumber('download')
    ->name('employees.documents.zip');

// HROne parity XO-3: a prepared bulk download of expense documents (signed link; only the person who asked for it; audited).
Route::get('/expense/downloads/{download}', ExpenseDocumentDownloadController::class)
    ->middleware($protected)
    ->whereNumber('download')
    ->name('expense.documents.zip');

// HROne parity XO-3: an expense report as a printable summary (signed link; the report's view policy; audited).
Route::get('/expense/reports/{report}/print', ExpenseReportPrintController::class)
    ->middleware($protected)
    ->whereNumber('report')
    ->name('expense.reports.print');

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

// Branded sign-in (HROne-style): the remembered organisation's logo and sign-in picture for the signed-out sign-in page
// (the encrypted workspace cookie picks the organisation, never the link), and its signed sign-in link.
Route::get('/sign-in/brand/{asset}', BrandImageController::class)
    ->middleware(['web', 'throttle:120,1'])
    ->whereIn('asset', ['logo', 'picture'])
    ->name('sign-in.brand');
Route::get('/sign-in/link/{workspace}', SignInLinkController::class)
    ->middleware(['web', 'signed', 'throttle:30,1'])
    ->where('workspace', '[a-z0-9-]{1,100}')
    ->name('sign-in.link');

Route::get('/sso/{connection}/redirect', [SsoController::class, 'redirect'])->middleware('web')->name('sso.redirect');
Route::get('/sso/{connection}/callback', [SsoController::class, 'callback'])->middleware('web')->name('sso.callback');

/*
| Public website (App\Domain\Website): "/login" for people who type it, robots.txt and the sitemap (indexing stays off
| unless an operator allows it), the draft preview for platform operators, the "talk to sales" form, and the operator's
| pages. The page route is a fallback route, so every workspace route above (and any added later) wins over it, and
| Website::assertSlugAllowed() refuses page addresses that start like a workspace route.
*/
// Named "login": Laravel's auth middleware sends signed-out visitors of protected routes (downloads, the preview) here,
// and so on to the workspace sign-in, which returns them to the page they asked for.
Route::redirect('/login', '/admin/login')->name('login');
Route::get('/robots.txt', [SiteController::class, 'robots'])->name('website.robots');
Route::get('/sitemap.xml', [SiteController::class, 'sitemap'])->name('website.sitemap');
Route::get('/site-preview/{slug?}', [SiteController::class, 'preview'])
    ->middleware($protected)
    ->where('slug', Website::SLUG_PATTERN)
    ->name('website.preview');
Route::post('/enquiry', [SiteController::class, 'enquire'])
    ->middleware(['web', 'throttle:5,1'])
    ->name('website.enquiry');
// Mobile verification for the contact form: send a one-time code (also limited per number and per address inside
// PhoneVerifications) and check it.
Route::post('/enquiry/code', [SiteController::class, 'sendCode'])
    ->middleware(['web', 'throttle:10,1'])
    ->name('website.enquiry.code');
Route::post('/enquiry/code/verify', [SiteController::class, 'verifyCode'])
    ->middleware(['web', 'throttle:30,1'])
    ->name('website.enquiry.verify');
Route::get('/{slug}', [SiteController::class, 'page'])
    ->middleware('web')
    ->where('slug', Website::SLUG_PATTERN)
    ->fallback()
    ->name('website.page');
