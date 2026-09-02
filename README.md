# JHS Teaching Load & Scheduling System

A Laravel application for Ateneo de Zamboanga University's Junior High School to digitize each department's Plantilla (teaching-load) data. This milestone delivers the **System Administrator** and **Department Chair** roles; the Academic Coordinator and Scheduling Engine come later.

Design and requirements live in [`docs/`](docs/) — see the [SRS](docs/Documentation/JHS%20System%20SRS.md), [architecture](docs/Documentation/JHS%20Laravel%20System%20Architecture.md), the [milestone spec](docs/superpowers/specs/2026-08-07-admin-chair-milestone-design.md), and the [design system](docs/design/JHS-design-system.md) (rendered in `docs/design/style-guide.html`).

## Stack

Laravel 13 · Blade + Alpine.js + Tailwind (the "Institutional Atenista" design system) · MySQL/MariaDB · `smalot/pdfparser` for plantilla extraction.

## System requirements

| Dependency | Version | Notes |
|---|---|---|
| PHP | ^8.3 (developed on 8.4) | needs the `pdo_mysql`, `mbstring`, `xml`, `curl`, `gd` extensions |
| Composer | 2.x | |
| Node.js | 18+ (LTS recommended) | ships with npm |
| MySQL / MariaDB | MySQL 8.x or MariaDB 10.6+ | any XAMPP/MAMP/native install works |

## Setup

Install the dependencies above for your OS, then:

### macOS (XAMPP)

1. **Start MySQL** in the XAMPP Control Panel (or `sudo /Applications/XAMPP/xamppfiles/xampp startmysql`).
2. **Create the database:**
   ```bash
   /Applications/XAMPP/xamppfiles/bin/mysql -u root -e "CREATE DATABASE IF NOT EXISTS jhs_load_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```
   The `.env` is preconfigured for XAMPP (`127.0.0.1:3306`, user `root`, empty password). For MAMP, set `DB_PORT=8889` and `DB_PASSWORD=root`.
3. **Install dependencies and build assets:**
   ```bash
   composer install
   npm install && npm run build
   ```
4. **Migrate and seed:**
   ```bash
   php artisan migrate --seed
   ```
5. **Run:**
   ```bash
   php artisan serve
   ```
   Then open http://127.0.0.1:8000.

### Windows (XAMPP)

1. **Install PHP 8.3+, Composer, and Node.js 18+** if not already present (XAMPP bundles PHP; verify its version with `php -v` and upgrade the XAMPP `php` folder if it's older than 8.3).
2. **Start MySQL** from the XAMPP Control Panel.
3. **Create the database** (PowerShell or Command Prompt):
   ```bat
   C:\xampp\mysql\bin\mysql -u root -e "CREATE DATABASE IF NOT EXISTS jhs_load_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```
   Copy `.env.example` to `.env` and set `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_USERNAME=root`, `DB_PASSWORD=` (empty), matching XAMPP's default MySQL credentials.
4. **Install dependencies and build assets:**
   ```bat
   composer install
   npm install && npm run build
   ```
5. **Migrate and seed:**
   ```bat
   php artisan migrate --seed
   ```
6. **Run:**
   ```bat
   php artisan serve
   ```
   Then open http://127.0.0.1:8000.

   > Use PowerShell or Git Bash rather than `cmd.exe` if any `artisan` command errors out on shell quoting.

### Linux (native MySQL/MariaDB)

1. **Install PHP 8.3+ with extensions, Composer, Node.js 18+, and MySQL/MariaDB** via your package manager, e.g. on Debian/Ubuntu:
   ```bash
   sudo apt install php8.3 php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-gd composer nodejs npm mysql-server
   ```
2. **Start MySQL:**
   ```bash
   sudo systemctl start mysql
   ```
3. **Create the database:**
   ```bash
   mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS jhs_load_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```
   Copy `.env.example` to `.env` and set `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_USERNAME`/`DB_PASSWORD` to match the MySQL user you created.
4. **Install dependencies and build assets:**
   ```bash
   composer install
   npm install && npm run build
   ```
5. **Migrate and seed:**
   ```bash
   php artisan migrate --seed
   ```
6. **Run:**
   ```bash
   php artisan serve
   ```
   Then open http://127.0.0.1:8000.

## Demo accounts

Password `password` for all.

| Email                  | Role                 |
| ---------------------- | -------------------- |
| `admin@jhs.test`       | System Administrator |
| `chair.fil@jhs.test`   | Filipino Chair       |
| `chair.cle@jhs.test`   | CLE Chair            |
| `chair.tle@jhs.test`   | TLE Chair            |
| `chair.sci@jhs.test`   | Science Chair        |
| `chair.math@jhs.test`  | Mathematics Chair    |
| `chair.mapeh@jhs.test` | MAPEH Chair          |
| `chair.soc@jhs.test`   | Social Studies Chair |

## What's here

- **System Administrator** — dashboard, user directory (create/edit/deactivate), system constants, the assignment-role → equivalent-hours lookup, and a full audit log.
- **Department Chair** — dashboard with live load figures and data-quality flags; upload a plantilla PDF; review and correct the extracted rows before importing; teacher roster; section / class-moderator / Honor's Class assignments; and submit-for-review, which locks editing.

Teacher and assignment data enter through the real import flow: upload a department's plantilla from `docs/Plantillas/`, correct the extracted rows, then confirm the import. PDF extraction is best-effort (names and status parse reliably; grade/section columns are flagged for the Chair to complete).

## Tests

```bash
php artisan test
```

Runs on in-memory SQLite (no MySQL needed for the suite). Covers RBAC boundaries, the submission state machine, and the full extraction-to-import pipeline against the real Filipino plantilla fixture.
