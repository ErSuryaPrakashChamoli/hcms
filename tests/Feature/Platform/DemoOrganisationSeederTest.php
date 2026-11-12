<?php

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Engagement\Models\SurveyResponse;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Loans\Models\Loan;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Platform\Actions\ProvisionTenantAction;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Recognition\Models\Recognition;
use App\Domain\Skills\Models\EmployeeSkill;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Demo\DemoOrganisation;
use Database\Seeders\Demo\DemoOrganisationSeeder;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/*
| The demo organisation: a fictional tenant run on purpose on any server, production included
| (`db:seed --class=Database\Seeders\Demo\DemoOrganisationSeeder --force`, or deploy.sh --demo). db:seed alone still
| never seeds demo data in production (DatabaseSeederTest).
*/

function seedDemoAsProduction(): void
{
    app()['env'] = 'production';
    try {
        // Every section is guarded (a failure is reported, the rest still runs): none may have been skipped. The
        // console is mocked for the whole run (Artisan::output() would hold only the last nested command's output).
        test()->artisan('db:seed', ['--class' => DemoOrganisationSeeder::class, '--force' => true])
            ->doesntExpectOutputToContain('Demo activity:')
            ->assertSuccessful();
    } finally {
        app()['env'] = 'testing';
    }
}

it('builds the demo organisation on production, idempotently, with one shared demo password', function () {
    config(['peopleos.demo.password' => 'Demo-Organisation-2026']);

    seedDemoAsProduction();
    $tenant = DemoOrganisation::tenant();
    $counts = fn () => app(TenantContext::class)->runAs($tenant, fn () => [
        'employees' => Employee::query()->count(),
        'users' => User::query()->count(),
        'attendance' => AttendanceRecord::query()->count(),
        'leave' => LeaveRequest::query()->count(),
        'approved_leave' => LeaveRequest::query()->where('status', 'approved')->count(),
        'runs' => PayrollRun::query()->count(),
        'recognitions' => Recognition::query()->count(),
        'survey_responses' => SurveyResponse::query()->count(),
        'expense_reports' => ExpenseReport::query()->count(),
        'loans' => Loan::query()->count(),
        'bank_accounts' => EmployeeBankAccount::query()->count(),
        'skills' => EmployeeSkill::query()->count(),
    ]);
    $first = $counts();

    seedDemoAsProduction();

    $accounts = User::query()->where('tenant_id', $tenant->id)->where('email', 'like', '%@demo.local')->get();
    expect($tenant->name)->toBe(DemoOrganisation::NAME)
        ->and(DemoOrganisation::isMarked($tenant))->toBeTrue()
        ->and($first['employees'])->toBeGreaterThanOrEqual(20)
        ->and($first['attendance'])->toBeGreaterThan(100)
        ->and($first['leave'])->toBeGreaterThan(3)
        // Past leave needs a policy in force before it: the demo's policies date from before its first joiner.
        ->and($first['approved_leave'])->toBeGreaterThanOrEqual(5)
        ->and($first)->toMatchArray(['recognitions' => 4, 'survey_responses' => 3, 'expense_reports' => 2, 'loans' => 2])
        ->and($first['bank_accounts'])->toBeGreaterThanOrEqual(20)
        ->and($first['skills'])->toBeGreaterThan(10)
        ->and($first['runs'])->toBeGreaterThan(0)
        // The second run duplicated nothing.
        ->and($counts())->toBe($first)
        ->and(Tenant::query()->count())->toBe(1)
        // Every persona the summary lists can sign in with the configured password; nobody keeps a fixture password.
        ->and(collect(DemoOrganisationSeeder::SIGN_INS)->every(fn ($email) => $accounts->firstWhere('email', $email) !== null))->toBeTrue()
        ->and($accounts->every(fn (User $user) => Hash::check('Demo-Organisation-2026', $user->password)))->toBeTrue()
        ->and($accounts->contains(fn (User $user) => Hash::check('password', $user->password)))->toBeFalse()
        // No platform operator is ever a demo account.
        ->and(User::query()->where('is_platform_admin', true)->count())->toBe(0);
});

it('rotates every demo password when PEOPLEOS_DEMO_PASSWORD changes, and refuses a short one', function () {
    config(['peopleos.demo.password' => 'Demo-Organisation-2026']);
    seedDemoAsProduction();
    config(['peopleos.demo.password' => 'Rotated-Demo-Pass-77']);
    seedDemoAsProduction();

    $admin = User::query()->where('email', DemoOrganisation::ADMIN_EMAIL)->sole();
    expect(Hash::check('Rotated-Demo-Pass-77', $admin->password))->toBeTrue();

    config(['peopleos.demo.password' => 'short']);
    app()['env'] = 'production';
    try {
        expect(fn () => app(DemoOrganisationSeeder::class)->setContainer(app())->__invoke())
            ->toThrow(RuntimeException::class, 'at least 12 characters');
    } finally {
        app()['env'] = 'testing';
    }
});

it('never fills a real tenant that already uses the demo address on production', function () {
    app(ProvisionTenantAction::class)->handle(['name' => 'Demo Customer Ltd', 'slug' => DemoOrganisation::SLUG]);
    config(['peopleos.demo.password' => 'Demo-Organisation-2026']);

    app()['env'] = 'production';
    try {
        expect(fn () => app(DemoOrganisationSeeder::class)->setContainer(app())->__invoke())
            ->toThrow(RuntimeException::class, 'is not the demo organisation');
    } finally {
        app()['env'] = 'testing';
    }

    $tenant = DemoOrganisation::tenant();
    expect(DemoOrganisation::isMarked($tenant))->toBeFalse()
        ->and(app(TenantContext::class)->runAs($tenant, fn () => Employee::query()->count()))->toBe(0);
});

it('never mails a reserved, undeliverable address on production', function () {
    config(['mail.default' => 'array']);
    $transport = fn () => app('mail.manager')->mailer('array')->getSymfonyTransport();
    $send = fn (array $to) => Mail::raw('Hello', fn (Message $m) => $m->to($to)->subject('Demo'));

    app()['env'] = 'production';
    try {
        $send(['priya.nair@demo.local']);
        $send(['priya.nair@demo.local', 'hr@markedge.in']);
    } finally {
        app()['env'] = 'testing';
    }
    $send(['priya.nair@demo.local']);

    $messages = $transport()->messages();
    expect($messages)->toHaveCount(2)
        // Production: the demo-only message was not sent; the mixed one went to the real address alone.
        ->and(collect($messages->first()->getEnvelope()->getRecipients())->map->getAddress()->all())->toBe(['hr@markedge.in'])
        // Other environments are untouched.
        ->and(collect($messages->last()->getEnvelope()->getRecipients())->map->getAddress()->all())->toBe(['priya.nair@demo.local']);
});
