# Deploy commands (production server)

The commands to run on the server, in order. Background and the reasons behind each step are in
[production-configuration.md §9](production-configuration.md#9-deploy-sequence); workers and cron in
[queue-and-scheduler.md](queue-and-scheduler.md). Run everything from the application root, as the user that owns it.

## 1. Every deploy (after a merge into `main`)

Take a database backup first, then one command does the rest:

```bash
./deploy.sh            # asks whether the backup was taken
./deploy.sh --yes      # the backup was taken: no question
./deploy.sh --yes --demo   # also create or top up the demo organisation (§3)
```

`deploy.sh` runs, and stops at the first failure (the site stays in maintenance mode until the run succeeds):

```bash
php artisan down --retry=60
git fetch origin main && git checkout main && git merge --ff-only origin/main
composer install --no-dev --optimize-autoloader --no-interaction
npm ci --include=dev --ignore-scripts --no-audit --no-fund
npm run build                                  # public/build: the theme, calendars, every .pos-* screen
php artisan migrate --force --isolated
php artisan db:seed --force                    # baseline only: permissions, statutory packs (never demo data)
php artisan optimize:clear --except=cache
php artisan optimize                           # config, routes, events, views, Filament components, icons
php artisan queue:restart
php artisan db:seed --class='Database\Seeders\Demo\DemoOrganisationSeeder' --force   # only with --demo
php artisan peopleos:readiness                 # reported, never keeps the site down
php artisan up
```

Run these by hand only to repeat one step; the order matters (assets before caches, caches before the demo seeder).

## 2. First install on a new server (once)

```bash
cp .env.example .env               # then fill it in: production-configuration.md §1–§8
php artisan key:generate
composer install --no-dev --optimize-autoloader --no-interaction
npm ci --include=dev --ignore-scripts && npm run build
php artisan migrate --force        # first install: without --isolated (the cache table does not exist yet)
php artisan db:seed --force        # baseline; creates the first operator when PEOPLEOS_BOOTSTRAP_OPERATOR_EMAIL is set
php artisan optimize
```

Then set up the two background processes (they keep running; `deploy.sh` only restarts the workers):

```bash
# Queue worker, under Supervisor or systemd (autorestart=true, stopwaitsecs=3720)
php artisan queue:work --sleep=3 --tries=3 --timeout=3700 --memory=512 --max-time=3600

# Scheduler: crontab -e
* * * * * cd /path/to/peopleos && php artisan schedule:run >> /dev/null 2>&1
```

Without the worker no e-mail, notification or export goes out; without the scheduler nothing runs on time (leave
accrual, attendance processing, reminders, reports).

## 3. Demo organisation (to show the product working)

A fictional company, **Demo Group** (address `demo`), with every module filled through the real services: two
companies and an organisation tree, two dozen people, shifts, holidays, attendance from the first of last month to
yesterday, leave balances with past and pending leave, salaries, bank accounts and a payroll run, performance goals and
feedback, learning, assets, the HR help desk, knowledge, announcements, an exit, expenses, loans, recognition, a pulse
survey, skills, projects, a tax declaration, a joiner in preboarding and notifications. It is never added by
`db:seed`; running this class is the decision.

The first run takes about two minutes and queues one attendance job per punch for the worker (harmless: the days are
already processed). Later runs take seconds and only add the attendance days since the last run.

```bash
# 1. In .env: one password for every demo account (12+ characters), then refresh the config cache
PEOPLEOS_DEMO_PASSWORD=choose-a-long-demo-password
php artisan config:cache

# 2. Create it (or top it up; safe to repeat, nothing is duplicated)
php artisan db:seed --class='Database\Seeders\Demo\DemoOrganisationSeeder' --force
```

`./deploy.sh --demo` does step 2 on every deploy. Without `PEOPLEOS_DEMO_PASSWORD`, each run generates a password,
prints it once and gives it to every demo account. Changing the value and running again rotates them all.

Sign in at `https://<your-domain>/admin/login`:

| Lens | E-mail |
|---|---|
| Tenant super admin (everything) | `admin@demo.local` |
| HR admin | `kavya.menon@demo.local` |
| HR business partner | `neha.kapoor@demo.local` |
| Payroll lead | `arjun.bose@demo.local` |
| Executive / CEO | `meera.iyer@demo.local` |
| Manager | `amit.verma@demo.local` |
| Employee | `priya.nair@demo.local`, `rahul.sharma@demo.local` |

What to expect on production:
- `demo.local` is undeliverable on purpose: production never sends mail to it (in-app notifications still arrive).
- `peopleos:readiness` reports **FAIL "No demo or test sign-in accounts"** while the demo can sign in. That line is
  true; it is how an operator sees that demo data lives on this server.
- Payroll is calculated but cannot be finalised until the statutory rules are verified (as for every tenant).
- The platform operator is never a demo account (`PEOPLEOS_BOOTSTRAP_OPERATOR_EMAIL`, §2).
- The seeder refuses to touch a tenant at the address `demo` that it did not create.

To take the demo off a server, a platform operator suspends the Demo Group tenant on the Tenants page (tenant deletion
stays off); stop passing `--demo`.

## 4. Checks after a deploy

```bash
php artisan peopleos:readiness                    # PASS / WARN / FAIL per area
php artisan peopleos:config:validate --as-production
php artisan about                                  # environment, caches, drivers
php artisan migrate:status | tail -5               # nothing pending
php artisan queue:failed                           # failed jobs, if any
curl -s https://<your-domain>/health/ready
tail -n 100 storage/logs/laravel.log
```

## 5. When something is wrong

| Symptom | Command |
|---|---|
| Old screens, a new page missing | `npm run build && php artisan optimize:clear --except=cache && php artisan optimize` |
| A changed `.env` value is ignored | `php artisan config:cache` |
| A new permission is missing from roles | `php artisan peopleos:sync-permissions` |
| Jobs stuck after a deploy | `php artisan queue:restart` (the supervisor starts fresh workers) |
| Retry failed jobs once the cause is fixed | `php artisan queue:retry all` |
| The deploy failed half-way, site in maintenance | fix the cause, run `./deploy.sh --yes` again; `php artisan up` only when code, assets and database match |
| An operator lost their authenticator | `php artisan peopleos:mfa:reset <email> --reason="..."` |
