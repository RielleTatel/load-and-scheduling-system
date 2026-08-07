# JHS Teaching Load & Scheduling System

A Laravel application for Ateneo de Zamboanga University's Junior High School to digitize each department's Plantilla (teaching-load) data. This milestone delivers the **System Administrator** and **Department Chair** roles; the Academic Coordinator and Scheduling Engine come later.

Design and requirements live in [`docs/`](docs/) — see the [SRS](docs/Documentation/JHS%20System%20SRS.md), [architecture](docs/Documentation/JHS%20Laravel%20System%20Architecture.md), the [milestone spec](docs/superpowers/specs/2026-08-07-admin-chair-milestone-design.md), and the [design system](docs/design/JHS-design-system.md) (rendered in `docs/design/style-guide.html`).

## Stack

Laravel 13 · Blade + Alpine.js + Tailwind (the "Institutional Atenista" design system) · MySQL/MariaDB · `smalot/pdfparser` for plantilla extraction.

## Setup (macOS + XAMPP)

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

## Demo accounts

Password `password` for all.

| Email | Role |
|---|---|
| `admin@jhs.test` | System Administrator |
| `chair.fil@jhs.test` | Filipino Chair |
| `chair.cle@jhs.test` | CLE Chair |
| `chair.tle@jhs.test` | TLE Chair |
| `chair.sci@jhs.test` | Science Chair |
| `chair.math@jhs.test` | Mathematics Chair |
| `chair.mapeh@jhs.test` | MAPEH Chair |
| `chair.soc@jhs.test` | Social Studies Chair |

## What's here

- **System Administrator** — dashboard, user directory (create/edit/deactivate), system constants, the assignment-role → equivalent-hours lookup, and a full audit log.
- **Department Chair** — dashboard with live load figures and data-quality flags; upload a plantilla PDF; review and correct the extracted rows before importing; teacher roster; section / class-moderator / Honor's Class assignments; and submit-for-review, which locks editing.

Teacher and assignment data enter through the real import flow: upload a department's plantilla from `docs/Plantillas/`, correct the extracted rows, then confirm the import. PDF extraction is best-effort (names and status parse reliably; grade/section columns are flagged for the Chair to complete).

## Tests

```bash
php artisan test
```

Runs on in-memory SQLite (no MySQL needed for the suite). Covers RBAC boundaries, the submission state machine, and the full extraction-to-import pipeline against the real Filipino plantilla fixture.
