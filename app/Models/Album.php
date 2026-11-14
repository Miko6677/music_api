<?php

namespace App\Models;

class Album extends CatalogEntity
{
    const EDITION_FIELDS = ['release_year', 'edition', 'release_country', 'label', 'catalog_number', 'musicbrainz_id', 'total_discs', 'total_tracks'];

    const FIELDS = [...parent::FIELDS, 'release_date', 'genre', ...self::EDITION_FIELDS, 'artwork'];

    public function artists()
    {
        return $this->belongsToMany(Artist::class, 'album_artist');
    }

    public function songs()
    {
        return $this->belongsToMany(Song::class, 'album_song')->withPivot(['id', 'disc_number', 'track_number', 'version'])->withTimestamps()->orderByPivot('disc_number')->orderByPivot('track_number');
    }

    protected function casts(): array
    {
        return [...parent::casts(), 'release_year' => 'integer', 'total_discs' => 'integer', 'total_tracks' => 'integer', 'artwork' => 'array'];
    }
}
