
# Dordielist

Dordielist is a self-hosted media catalog and management application built with Laravel. It aggregates and displays information for multiple media types (anime, manga, manhwa, doujins, visual novels, etc.), supports user accounts with two-factor authentication, collections, favorites, and syncs media and chapters/episodes from disk or external APIs (Anilist, VNDB).

## Key features
- Media catalog and detail pages (Anilist / VNDB integration)
- User registration, login and optional 2FA via Laravel Fortify
- Collections and favorites (attach/remove media, random pick)
- Episode / Chapter reading with sync-from-disk support
- Background jobs and queueable sync tasks

## Tech stack
- Backend: PHP 8.2, Laravel 11
- Auth: Laravel Fortify (2FA + rate limiting)
- Frontend: Vite, TailwindCSS, Axios

## Quickstart (development)
Follow these steps in the project root. These are the minimal commands to get the app running locally.

```sh
# 1) Install PHP dependencies
composer install

# 2) Copy env and prepare a local sqlite DB
cp .env.example .env
# ensure DB_CONNECTION=sqlite in .env or set DB_DATABASE=database/database.sqlite

# 3) Generate app key & run migrations
php artisan key:generate
php artisan migrate

# 4) Install frontend deps and run Vite (dev mode)
npm install
npm run dev

# 5) Start the application (or use the convenience script below)
php artisan serve
```


## Environment / configuration notes
- Database: default local dev uses SQLite at `database/database.sqlite`. For production, configure `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE` etc.
- External APIs: provide credentials/keys for Anilist or VNDB if you intend to run syncs.
- Mail: configure `MAIL_*` env vars (PHPMailer is available via notifications).

## Important implementation notes
- User model uses a custom primary key `user_id` and disables timestamps. Some packages may assume `id` or timestamp columns — adapt accordingly.
- Fortify is configured in `app/Providers/FortifyServiceProvider.php` (login, registration, two-factor views and rate limiting).
- `app/Models/Media.php` casts several fields to arrays (genres, tags, languages). Ensure migrations store those columns as JSON/text that can hold JSON.

## Where to look in the code
- Routes: `routes/web.php` — main app flow and API endpoints
- Controllers: `app/Http/Controllers/` (AnilistController, VndbController, CollectionController, EpisodeController, etc.)
- Models: `app/Models/` (Media, User, Collection, Episode, Chapter)
- Views: `resources/views/` (Blade templates and partials)
- Config: `config/` (Fortify, vndb, filesystem, etc.)
