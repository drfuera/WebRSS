# Private RSS Reader

A self-hosted, single-file RSS/Atom reader. No database, no build step, no dependencies — just one `index.php` you drop on any PHP server.

![Screenshot](docs/screenshot.png)

## Features

- **Single-file app** — backend, API, and frontend (HTML/CSS/JS) all live in `index.php`. Deploy by copying one file.
- **Folders** — organize feeds into folders, with drag-and-drop reordering and moving.
- **Unread tracking** — per-feed and per-folder unread counts, mark single articles or entire folders/feeds as read.
- **Search** — full-text search across every cached article (title + body), across all feeds at once.
- **Auto-discovery** — add a site URL and the reader will detect its RSS/Atom feed automatically.
- **Smart refresh** — feeds are cached and only re-fetched when stale (5 min TTL), with a shared throttle so multiple open tabs/clients don't hammer the source site with requests. Manual "refresh all" fetches every feed in parallel.
- **Background auto-refresh** — the UI polls for new articles every 5 minutes.
- **Safe article rendering** — article HTML is parsed and passed through a strict tag/attribute allowlist before display (no scripts, no inline event handlers, only safe links/images/media), so you can safely read content from untrusted feeds.
- **Image lightbox** — click any image inside an article to open it full-size in a modal overlay; click outside, hit the close button, or press Esc to dismiss.
- **Syncplay** — watch a YouTube video embedded in an article in sync with anyone else who has the reader open. One person starts playback and it's mirrored (play/pause/seek, with clock-offset and drift correction) to every other connected client in real time. A small on-screen control lets you fine-tune a fixed offset per device (useful if your TV/monitor has extra decode latency).
- **Resizable, responsive UI** — draggable sidebar and article-list panes, with a mobile-friendly collapsible drawer.
- **JSON storage** — feeds, folders, and cached articles are stored as flat JSON files under `./data/`, no database required.

## Requirements

- PHP 7.4 or newer
- `ext-dom` and `ext-json` (required)
- `ext-curl` (optional — falls back to `file_get_contents` if unavailable; parallel "refresh all" requires `curl_multi`, otherwise it falls back to sequential fetching)

## Installation

1. Copy `index.php` to a directory served by PHP (e.g. via Apache, Nginx + PHP-FPM, or `php -S`).
2. Make sure the web server user can create and write to a `data/` directory next to `index.php` (it will be created automatically on first run).
3. Open the page in your browser and start adding feeds.

```bash
# Quick local test
php -S localhost:8000
```

Then visit `http://localhost:8000`.

## Usage

- **Add a feed** — click "Add feed" and paste either a direct feed URL or a website URL (auto-discovery will find the feed for you).
- **Organize** — create folders and drag feeds into (or between) them; drag folders themselves to reorder.
- **Read** — select "All articles", a folder, or a single feed to view its items. Mark items as read individually or in bulk ("Mark all as read"), or right-click an article to mark it unread again, copy its link, or open it in a new tab.
- **Search** — type in the search box in the sidebar to search titles and article bodies across every feed at once.
- **View images** — click any image in an article to open it enlarged in an overlay.
- **Watch together** — click the "▶ Syncplay" button on an article containing a YouTube video to start a synced watch session; anyone else with the reader open joins automatically.
- **Refresh** — trigger a manual refresh of all feeds with the refresh button, or let the background auto-refresh (every 5 minutes) handle it.

## Data storage

All data is stored as JSON under `data/`:

- `data/feeds.json` — feed and folder definitions
- `data/cache/*.json` — cached articles per feed (including read/unread state)
- `data/syncplay.json` — current Syncplay session state (video, position, playing/paused)
- `data/last_refresh.txt` — throttle timestamp for "refresh all"

No database setup is required. Back up the `data/` directory to preserve your feeds and read state.

## Security notes

This app has no built-in authentication. If exposing it beyond `localhost`, put it behind your own auth layer (e.g. HTTP basic auth, a reverse proxy with access control, or a VPN).

Article content from feeds is sanitized server-side (allowlisted tags/attributes, scripts stripped, `javascript:`-style URLs blocked) before being sent to the browser, but as with any feed reader you should still only subscribe to sources you trust.

## License

Add a license of your choice (e.g. MIT) here.
