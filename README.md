# music

## Build

```bash
docker build -t music/frankenphp:8.5 .
```

## Run

```bash
composer install --optimize-autoloader --no-dev -vvv
docker compose up -d
```

## Upload metadata from MusicBrainz

Authenticated API clients can search public release metadata through `GET /api/metadata/musicbrainz/releases/search?q=...&artist=...`, load a selected release through `GET /api/metadata/musicbrainz/releases/{mbid}`, and import its front cover through `GET /api/metadata/musicbrainz/releases/{mbid}/cover`. The endpoints return release/track metadata and a validated image response; cover files still enter the library through the existing upload flow.

MusicBrainz requests are identified as `EmosMusicAPI`, paced to at most one request per second, and cached for 12 hours. Cover images are fetched only from Cover Art Archive/Archive.org hosts, limited to supported image types and the configured cover-size limit. The public artwork rights remain with their respective owners; clients should ask users to confirm they have permission before importing a cover.
