# Birthdays — celebrated birthday, wishes, day notices and the monthly list

For: engineers changing how PeopleOS handles birthdays. Extends the person record of Phase 3 (`phase-3-employee-core.md`). Built 2026-10-23.

## What a client can do

- **Record a celebrated birthday** when it differs from the date of birth on official papers (Edit profile and the hire wizard: *Birthday → Celebrated in / On day*; day and month only). Blank means the date of birth. The date of birth itself is never changed.
- **Keep a birthday private** (*Show to colleagues* off): colleagues neither see it nor are reminded; HR still does.
- **Employees** open **Birthdays** (Me): today's birthdays with **Send wishes**, the next two weeks, any month, and the **wishes they received**. **My birthday** lets an employee set their own celebrated day and privacy. Home shows a **Birthdays today** card with one-click **Wish**.
- **HR** (permission `celebration.manage`) sees everyone in their access scope, including private birthdays (marked), downloads a month as CSV, and opens **Set up**:
  - colleagues see birthdays of: the whole organisation, the same company, or their team;
  - on the day, remind: nobody, the line manager, or the manager + team-mates + direct reports;
  - the built-in greeting to the employee (on/off, text with `{name}` and `{organisation}`);
  - the month's list: on/off, and how many days before the month starts (0 = on the 1st);
  - the day's list each morning (on/off), and whether lists and greetings are also emailed.

## Where things are

| Piece | Purpose |
|---|---|
| `People\Models\Person` | `birthday_month`, `birthday_day`, `birthday_shared`; `birthday_key` (month × 100 + day) derived on save from the celebrated birthday, else the date of birth. Invalid days (31 April) are refused; a month without a day clears both. `birthday_key` is kept out of the audit trail (derived). |
| `People\Models\BirthdayWish` | One wish per sender, recipient and year (unique). Audited without the message (the sender's own words). Access-scoped by the recipient. |
| `People\Services\Birthdays` | Who is celebrated (`on`, `inMonth`, `upcoming`, `countOn`), who may see whom (`query`), `wish`, `wishesFor`, `sendDayNotices`, `sendMonthlyList`, `digestMonthDue`, settings (`celebration.birthdays.*`). |
| `peopleos:birthdays:send` (`BirthdayNotices`) | Hourly. Each tenant is handled once its own clock (tenant timezone) passes 06:00; `scheduler_claims` keys `birthdays.day` (per date) and `birthdays.month` (per month) make it once. `--force` resends. |
| Filament | `Pages\Birthdays` (+ `resources/views/filament/pages/birthdays*`), `Support\BirthdayActions::wish` (page and Home), Home side card `home/birthdays.blade.php` (`HomeComposer::birthdays`), `EmployeeForm::birthdayFields`, Employee 360 *Birthday* entry. |

## Rules

- **Day and month only.** Colleagues never see the year or the age. The CSV for HR carries the day, not the date of birth.
- **Today is the tenant's date** (`tenants.timezone`). A 29 February birthday is celebrated on 28 February in other years.
- **Who is celebrated:** employees in Onboarding, Joined, Probation, Confirmed, Active, On leave or Notice period (not pre-joiners, suspended, exited or alumni).
- **Visibility:** `celebration.manage` sees its access scope (`AccessScopes`, applied explicitly so a system run for a recipient is right too). `celebration.view` sees shared birthdays within `celebration.birthdays.visible_to`. A wish is accepted only on the day, never to oneself, once a year, and only for someone the sender can see.
- **Notices** (`Notifier`, events `celebration.birthday.*`, which open the Birthdays page from the notification centre):
  - `celebration.birthday.greeting` to the employee (in-app, + email when `celebration.birthdays.email`);
  - `celebration.birthday.today` to the people around them (in-app; never for private birthdays) and the day's list to celebration managers;
  - `celebration.birthday.month` — the month's list to celebration managers, each list narrowed to the recipient's scope; private birthdays marked;
  - `celebration.birthday.wish` to the recipient (in-app, with the message).
- **Tenant rules and workflows:** the daily run also emits `employee.birthday` (`EmployeeReminderDue`), so a tenant can add its own notification rule (e.g. an email card) or start a workflow. Turn the built-in greeting off if a rule replaces it.
- **Month list timing:** sent when *today + lead days* falls in the first week of a month; a missed run catches up within that week, never later (the page has the list).

## Schema (`2026_11_07_100001_birthdays`)

- `people`: `birthday_month`, `birthday_day`, `birthday_key`, `birthday_shared` (default true); index `(tenant_id, birthday_key)`. The migration fills `birthday_key` from existing dates of birth.
- `birthday_wishes` (tenant-owned): `employee_id`, `sender_user_id`, `sender_employee_id`, `year`, `message`, `read_at`; unique `(employee_id, sender_user_id, year)`. Listed under *people* in `DataInventory`.

## Permissions

`celebration.view` (Employee, Manager, HR Executive, HR Manager, Account Owner) and `celebration.manage` (Tenant HR Admin, HR Manager, Account Owner; Tenant Super Admin through `*`). Run `php artisan peopleos:sync-permissions` after deploying, so existing tenants' system roles get them.

## Not done

- Employee import and the API do not carry the celebrated birthday yet (date of birth only).
- Work anniversaries are not celebrated.
