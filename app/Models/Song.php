<?php

namespace App\Models;

class Song extends CatalogEntity
{
    const LIST_FIELDS = [...parent::FIELDS, 'recording_id', 'library_id', 'release_date', 'genre', 'recording_version'];

    const FIELDS = self::LIST_FIELDS;

    protected $attributes = ['description' => '', 'version' => 1];

    protected static function booted(): void
    {
        static::creating(function (Song $song) {
            if (!$song->recording_id) {
                $song->recording_id = Recording::create(['name' => $song->name, 'recording_version' => $song->recording_version ?? '', 'user_id' => $song->user_id])->id;
            }
        });
    }

    public function recording()
    {
        return $this->belongsTo(Recording::class);
    }

    public function lyrics()
    {
        return $this->hasMany(SongLyric::class)->select(SongLyric::FIELDS)->orderBy('sort_order')->orderBy('id');
    }

    public function artists()
    {
        return $this->belongsToMany(Artist::class, 'song_artist');
    }

    public function albums()
    {
        return $this->belongsToMany(Album::class, 'album_song')->withPivot(['id', 'disc_number', 'track_number', 'version'])->withTimestamps()->orderByPivot('created_at')->orderByPivot('id');
    }

    public function library()
    {
        return $this->belongsTo(MediaLibrary::class, 'library_id');
    }

    public function albumTracks()
    {
        return $this->hasMany(AlbumTrack::class);
    }

    public function files()
    {
        return $this->hasMany(StoredFile::class);
    }

    public function fans()
    {
        return $this->belongsToMany(User::class, 'favorites')->withTimestamps();
    }
}
