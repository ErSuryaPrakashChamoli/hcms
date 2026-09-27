<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>

# Markedge PeopleOS — project notes

- Product blueprint (130 sections, source of truth for scope and phasing): `docs/peopleos-blueprint.md`.
- What is actually built and the conventions to follow: `docs/architecture/` (one file per phase).
- Domain code lives in `app/Domain/{Module}/{Models,Services,Actions,Policies,Enums}`; cross-cutting
  primitives in `app/Support/`. Tenant-owned models use `BelongsToTenant` + `Auditable`
  (+ `HasEffectiveDates` where history matters). Never expose `tenant_id` in forms.
- Permission keys are declared in `config/peopleos.php`; run `php artisan peopleos:sync-permissions` after adding any.
- Tests: `php artisan test` (Pest, SQLite in-memory). Helpers in `tests/Pest.php`: `provisionTenant()`, `actAsTenant()`, `tenantUser()`, `platformAdmin()`.
