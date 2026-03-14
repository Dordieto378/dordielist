# Dordielist

Dordielist is a private media-tracking and media-launching website built with Laravel. It combines metadata from AniList and VNDB with files stored on disk, so the site works as both a tracker and a personal media hub.

Instead of only showing information from an API, the site keeps its own local database of anime, manga, manwha, doujinshi, hentai, and visual novels. That makes it possible to:

- browse a unified library
- sync list data from external services
- open locally stored visual novels
- read manga, manwha, and doujin chapters from disk
- watch locally stored anime episodes
- organize entries into favorites and custom collections

The result is a personal dashboard for everything I am watching, reading, collecting, or planning to play.

## What the website does

At a high level, the site solves two problems:

1. It pulls metadata and list state from services I already use, mainly AniList and VNDB.
2. It connects that metadata to local files on my own machine, so the library is not just informational.

### Main user-facing features

- Authenticated home page with curated sections like dropped series, high-rated wishlisted titles, and a paginated "most recent" library feed.
- Separate category pages for anime, manga, manwha, hentai, doujins, and visual novels.
- Fast quick-search modal that searches the local database across saved media.
- Media detail pages for AniList content, VNDB content, and doujin entries.
- Editable AniList entry popup for anime and manga items that can update progress, score, and list status locally and sync those changes back to AniList.
- Favorites and custom collections, including a system "Favorites" collection and random item picker.
- Episode syncing for anime and hentai stored in `storage/app/public`.
- Chapter/page syncing for manga, manwha, and doujin content stored in `storage/app/public`.
- Built-in episode playback page and chapter reader page.
- Visual novel launcher support that can detect `.exe` files inside a local game folder and launch the selected game directly.
- Registration flow with manual admin approval.
- Account settings, user management for admins, and Fortify-based two-factor authentication.
- Per-account AniList and VNDB credential storage in the profile settings page.

## How the site works

The core idea is that Dordielist uses one local `media` table as the main source of truth, even when the original data came from different places.

### Data sources

- AniList provides anime and manga list metadata.
- VNDB provides visual novel metadata and list metadata.
- Local folders provide the actual readable or playable content.

### Normalized local model

External entries are converted into a single local format and saved in the `media` table. This table stores things like:

- type
- titles
- cover image
- description
- genres and tags
- list status
- scores
- progress
- year and start date
- publisher / studio / developer-style metadata

That normalization is what makes the rest of the site possible. Once everything is stored locally, the UI does not need to make live API calls just to render normal pages.

### Local-first media handling

The site is not only a list tracker. It also mirrors local content into database tables:

- Anime and hentai videos become `episodes`.
- Manga, manwha, and doujin image folders become `chapters` and `chapter_pages`.
- Visual novel game folders can be scanned for launchable executables.

Because of that, the app can show a title page and also let me immediately read, watch, or launch the item from the same library.

## Architecture overview

### Backend

- Laravel 11
- PHP 8.2
- MySQL or MariaDB
- Laravel Fortify for auth and 2FA
- Blade templates for the UI
- Eloquent models for local data

### Frontend

- Blade views
- Tailwind CSS
- Vite
- AlpineJS loaded from CDN
- Plyr for video playback UI

### Important backend areas

- `AnilistController`: home page, AniList media detail pages, AniList sync, and AniList write-back updates.
- `VndbController`: VN detail pages, VNDB sync, VN list filtering, and VN description rendering.
- `DoujinController`: doujin page rendering and filesystem sync.
- `CollectionController`: favorites, collections, attach/remove logic, thumbnails, and random picker.
- `EpisodeController`: sync local video files and show the playback page.
- `ChapterController`: sync chapter pages from disk and render the reader.
- `SearchController`: quick-search endpoint backed by the local database.
- `VnLaunchController`: detect and launch local visual novel executables.
- `SettingsController`, `RegisterController`, `AdminController`: account management, credential storage, manual activation, and admin tools.

## Storage conventions

The project expects media files to live in predictable folders on the public storage disk.

### Anime and hentai videos

- `storage/app/public/anime/{mediaId}`
- `storage/app/public/hentai/{mediaId}`

The app scans these folders for `.mp4` and `.webm` files, creates `episodes` records, and can generate thumbnails with FFmpeg.

### Manga and manwha chapters

- `storage/app/public/manga/{mediaId}`
- `storage/app/public/manwha/{mediaId}`

Each folder can contain chapter subfolders, or just images directly in the root folder. Images are imported into `chapters` and `chapter_pages`.

### Doujin content

- `storage/app/public/doujin/{author}/{title}`

This structure is different from manga because the author name is part of the folder hierarchy. During sync, the app creates or updates local doujin media entries and imports the chapter/page structure.

### Visual novel game files

- `storage/app/public/games/{mediaId}`

The site scans inside this folder for `.exe` files, stores the selected relative path in the database, and can launch the game from the server machine.

### Generated thumbnails

- `storage/app/public/episode-thumbs/{mediaId}`

These are created for episode cards after video sync or by the thumbnail command.

## Sync flow

### AniList sync

AniList sync uses the authenticated user's AniList access token stored on their account settings page and fetches anime and manga list entries through GraphQL. Those entries are then converted into the local media schema.

During sync, the app:

- fetches list entries for `ANIME` and `MANGA`
- maps AniList status, score, progress, genres, dates, and titles into local columns
- derives local types like `anime`, `hentai`, `manga`, and `manwha`
- stores publishers, authors, or studios where possible
- removes old local AniList-linked media that is no longer present in the remote list
- lets the user edit progress, score, and list status from the AniList item page and push those updates back to AniList

### VNDB sync

VNDB sync uses the VNDB API token and username stored on the user's account settings page, looks up the VNDB user ID, fetches the user's VN list, and saves visual novel entries into the same `media` table.

During sync, the app:

- stores titles, developers, languages, tags, ratings, list status, and release year
- keeps a local copy of VN metadata so pages can render without live API dependence
- deletes stale VNDB-linked rows that are no longer present remotely

### Filesystem sync

After metadata exists in the database, local content can be linked to it:

- episode sync scans local video files
- chapter sync scans manga and manwha image folders
- doujin sync scans the doujin directory tree
- launcher detection scans local VN folders for executables

This split is intentional. Metadata sync and local file sync are separate jobs because they solve different problems.

## Collections and favorites

Collections are used to group saved items beyond the default external list status. The app includes:

- a system `Favorites` collection
- user-created collections
- per-item attach/remove logic
- stored thumbnails and titles for quicker rendering
- a random item picker for custom collections

This gives the project a second layer of organization on top of AniList and VNDB statuses.

## Authentication and account flow

The site is mostly private and requires authentication for normal use.

### Registration flow

New users can register, but they are not active immediately. Registration:

- validates username, email, password, and terms
- creates the user with `not_active` status
- emails an admin a signed activation link
- keeps the account inactive until the admin approves it

### Security features

- Laravel Fortify handles login and password flows.
- Two-factor authentication is enabled.
- Recovery codes are supported.
- Password rules are strict and require a long, complex password.
- Sensitive AniList and VNDB credential fields are stored on the user model and encrypted through Eloquent casts.

### Admin area

Admins can:

- activate new users
- edit usernames and emails
- change roles
- update account status

## How I built it

I built Dordielist as a Laravel application because I wanted something fast to iterate on, easy to host, and strong on server-side routing, forms, and database work. Blade let me move quickly without setting up a large SPA architecture, and Tailwind made it easy to shape the interface around my own browsing habits.

The first big design decision was to keep a local database copy of everything instead of querying AniList or VNDB on every page load. That gave me a unified schema across different media types and made it possible to add custom behavior that the original platforms do not support, like collections, local readers, episode playback, and game launching.

The second big decision was to treat the filesystem as part of the product. I did not want a site that only tells me what I own or follow. I wanted a library that actually connects the metadata to my local files. That is why the app imports episodes, chapters, pages, and game executables into its own database structures.

A later extension of that idea was letting the AniList detail page act as an editor instead of a read-only mirror. That popup lets me change score, progress, and list status inside Dordielist while still pushing those values back to AniList, so the local dashboard and the external list stay aligned.

I also separated external sync from local sync. AniList and VNDB handle metadata well, but they do not know anything about the files on my machine. By splitting those responsibilities, I kept the code simpler and made each sync step easier to reason about.

For the UI, I stayed with server-rendered Blade views and added lightweight JavaScript only where it improved the experience, like quick search, dropdown behavior, media playback, and edit modals. That kept the app responsive without turning the project into a heavy frontend application.

## Why the data model looks like this

The `media` table is the center of the application because every other feature depends on it.

- Category pages read from it.
- Search reads from it.
- Detail pages start from it.
- Favorites and collections point to it, directly or indirectly.
- Episode, chapter, and launcher features all attach local content to media IDs.

That design means I only have to solve normalization once. After that, the UI and the local-content features can stay mostly source-agnostic.

## Project structure

```text
app/
  Console/Commands/        Custom import and thumbnail commands
  Http/Controllers/        Sync, media pages, collections, settings, launchers
  Models/                  Media, Episode, Chapter, Favorite, Collection, User, Role
  Support/                 Episode thumbnail generation
database/
  migrations/              Schema for media, collections, chapters, episodes, auth
resources/
  views/                   Blade templates
  css/                     Tailwind entry and custom CSS
  js/                      Vite entry
routes/
  web.php                  Main site routes
storage/app/public/
  anime/, hentai/, manga/, manwha/, doujin/, games/, episode-thumbs/
```

## Setup

### Requirements

- PHP 8.2+
- Composer
- Node.js and npm
- MySQL or MariaDB
- FFmpeg if you want episode thumbnails

### Install

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan storage:link
```

### Environment variables

At minimum, configure these values in `.env`:

```env
APP_NAME="DordieList"
APP_URL=http://dordielist.test
ASSET_URL=http://dordielist.test

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3307
DB_DATABASE=dordielist
DB_USERNAME=root
DB_PASSWORD=

MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=you@example.com
MAIL_PASSWORD=your-smtp-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@dordielist.com
MAIL_FROM_NAME="DORDIELIST"

FILESYSTEM_DISK=public
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
FFMPEG_BIN=ffmpeg
```

After migrating, fill these fields from the website under account settings:

- AniList Access Token
- VNDB API Token
- VNDB Username
- VNDB Password

Note: the custom console commands in `app/Console/Commands` still read credentials from `.env` because they run without a logged-in user context.

## Running the project

### Development

```bash
composer run dev
```

That starts:

- the Laravel dev server
- the queue listener
- Laravel Pail for logs
- the Vite dev server

### Production-style assets

```bash
npm run build
```

## Useful commands

### Import external metadata

```bash
php artisan anilist:import
php artisan vndb:import
```

### Import local doujin content

```bash
php artisan doujin:import --all
php artisan doujin:import {mediaId}
```

### Generate missing episode thumbnails

```bash
php artisan episodes:thumbnails
php artisan episodes:thumbnails --media=123 --force
```

## Notes about the current implementation

- The site is strongly optimized around personal/private use rather than public multi-tenant scale.
- A lot of the UI logic lives directly in Blade templates, which kept development fast.
- Search is intentionally simple and uses the local database instead of an external search service.
- The project depends on consistent folder naming and media IDs for local sync features.
- Visual novel launching assumes the app runs on the same machine that has access to the game files.
- In-app AniList and VNDB sync now depends on credentials saved in the logged-in user's account settings.
- The custom Artisan import commands still use `.env` credentials at the moment.
- Automated test coverage is currently minimal and mostly placeholder-level.

## Future improvements

Some obvious upgrade paths would be:

- stronger automated test coverage
- background jobs for large sync operations
- better deduplication and conflict handling during imports
- richer admin tooling
- more polished mobile layouts in some pages
- a clearer separation between presentation logic and Blade templates on very large views

## Summary

Dordielist is a personal all-in-one media library for tracking, browsing, organizing, reading, watching, and launching the content I care about. The project is built around one idea: keep external metadata local, connect it to my filesystem, and make the website useful as a real library instead of just a tracker.
