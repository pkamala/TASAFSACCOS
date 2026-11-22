## TASAF SACCOS local setup

The application uses PostgreSQL 18. For local development, install PHP with `pdo_pgsql`, Composer, and Docker Compose.

```sh
composer install
cp -n .env.example .env
docker compose up -d --wait
php artisan key:generate
php artisan migrate
php artisan test
```

The Compose service listens on `127.0.0.1:55435` by default and stores data in a named volume. On first startup it creates the app database and `tasafsaccos_testing`, which PHPUnit uses for migrations and feature tests. If you use an existing PostgreSQL server, set the connection details in `.env` and create both databases before running the commands.

## Site branding and team walkthrough

Administrators open **Administration → Branding** to set the site display name, fallback initials, logo, and color palette. The logo appears in the site header and browser tab. Branding history records each change.

Use the [team walkthrough slides](docs/TASAF-SACCOS-Team-Walkthrough.pptx) to rehearse the role handoffs, ledger posting, cutover, and statement pilot with sample records.

## Demo data and opening imports

Use a separate database for demonstrations with `APP_DEMO_MODE=true` and a separate session cookie. This marks pages as demo and disables application email delivery in that environment. The sample opening journal uses a clearing account for the provisional savings and share totals. Keep demo activity out of the live database.

For a fresh start, open **Administration → Data imports**. Load the approved member register and chart of accounts first, then the member savings and share balances and the opening trial balance. Each upload accepts a single Excel worksheet or a CSV matching its template. Keep identifiers with leading zeros as text and paste approved values in place of formulas.

Use an agreed cutover date. Compare imported counts, savings and shares totals, outstanding loan principal, and the opening trial balance with the signed exports. Review the outstanding loan schedule files before mapping them into live loan records.

## Local PostgreSQL backup and restore

Run from the project directory. This creates a private backup directory outside the repository. The archive contains member and financial records, including any board minutes and bank checklists uploaded through the app.

```sh
(
    set -eu
    umask 077
    backup_dir=$(mktemp -d "$HOME/tasaf-backup.XXXXXXXX")
    docker compose exec -T postgres sh -c \
        'pg_dump --username="$POSTGRES_USER" --format=custom "$POSTGRES_DB"' \
        > "$backup_dir/database.dump.partial"
    mv "$backup_dir/database.dump.partial" "$backup_dir/database.dump"
    printf '%s\n' "$backup_dir/database.dump"
)
```

Rehearse the restore in a new database. Replace the archive path with the one printed above. If the rehearsal database already exists, database creation fails and the restore stops. Keep the application and scheduler connected to their existing database during the rehearsal.

```sh
(
    set -eu
    archive=/absolute/path/to/database.dump
    test -r "$archive"
    restore_db="tasaf_restore_check_$(date +%s)_$$"
    docker compose exec -T postgres sh -c \
        'createdb --username="$POSTGRES_USER" --template=template0 "$1"' sh "$restore_db"
    trap 'docker compose exec -T postgres sh -c '\''dropdb --username="$POSTGRES_USER" "$1"'\'' sh "$restore_db"' EXIT
    docker compose exec -T postgres sh -c \
        'pg_restore --username="$POSTGRES_USER" --dbname="$1" --exit-on-error --single-transaction --no-owner --no-privileges' \
        sh "$restore_db" < "$archive"
    docker compose exec -T postgres sh -c \
        'psql -X --set=ON_ERROR_STOP=1 --username="$POSTGRES_USER" --dbname="$1" -c "SELECT count(*) AS ledger_accounts FROM chart_accounts WHERE is_ledger_account"' \
        sh "$restore_db"
)
```

Record the result and compare restored counts and financial reports with the source. Keep production backups in protected off-machine storage and retain the application configuration and `APP_KEY` separately in your secrets store. This rehearsal uses the local Compose role. For a production restore, provision the intended database roles and grants and review statement delivery status before enabling the scheduler.

## Loan workflow roles

Members and staff use the same sign-in page. To set up or reset a member login, open **Administration → Members**, select the member, and send a one-time email under **Member login**. Configure an outgoing mailing account and set `APP_URL` to the address members can reach. The member chooses their own password, and the link expires after use or after 60 minutes.

For the initial assignment and after each handover, use **Board roles** in the admin portal. Set up the incoming officer’s regular member account, then assign the role by membership number and record the appointment reference. The outgoing officer keeps their member account. Only the Secretary can decide the Chairperson’s personal loan, and only the Chairperson can decide the Secretary’s personal loan.

An administrator submits loan applications and prepares payment lists. A Chairperson or Secretary records the board decision on a new loan and attaches the minutes as a PDF. For disbursements, an administrator prints the approved checklist and uploads the bank-stamped PDF with the confirmed transfer references. For repayments, an administrator selects a payroll month under **Operations → Payment lists → Prepare a payment list**. The app uses outstanding installments due within the month and recurring savings and shares configured on member profiles. Review the generated list, print it after board approval, and record the actual payroll reference once TASAF Accounts confirms the listed deductions. Configure member contributions from verified instructions before generating the list. New contribution settings apply to newly generated lists.

## Monthly member statements

Administrators can open **Payment lists → Monthly statement drafts** to generate and export principal, contributions, and savings from recorded transactions. Drafts need a verified opening balance dated before the month starts. Members with legacy loans are held for reconciliation. Review the figures and recipient addresses, then open **Review and queue statements → Preview system drafts** to queue the reviewed figures directly. CSV import remains available for reports approved outside the system. The portal records who queued it and the finance review reference.

Prepare a reviewed CSV for a closed month. Use the current registration number, a verified email that matches the member profile, and amounts in TZS with up to two decimal places. Each member can have one statement per month. Figures in the CSV are the source for delivery while historical financial data is being reconciled.

```csv
registration_no,email,loan_balance,monthly_contribution,savings_balance
10415,member@example.com,1000000.25,25000,350000
```

```sh
php artisan statements:import 2026-10 /secure/path/statements.csv
php artisan statements:import 2026-10 /secure/path/statements.csv --apply
php artisan statements:send 2026-10
```

The first command validates without writing. The last command previews the number of pending emails. Configure the SMTP sender under **Administration → Mailing accounts**, then select **Use for outgoing mail**. The app encrypts the SMTP password, and administrators can switch back to the server mail settings. Once the sender is configured, an operator can send with `php artisan statements:send 2026-10 --send`. To automate delivery, open **Administration → Mailing accounts → Automatic statement delivery**, enable it, and run the Laravel scheduler. The saved setting takes precedence over `MONTHLY_STATEMENT_EMAIL_ENABLED`. The same page stores optional incoming POP3 or IMAP settings for future inbox integration. The scheduled command sends pending statements for the prior month on the fifth day at 09:00 East Africa Time.

Sent statements are skipped on subsequent runs. An address mismatch holds a statement. For records marked `sending` after an interrupted run or `needs_review` after a delivery error, verify provider acceptance before attempting another delivery.

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
