<?php

namespace App\Models;

class Artist extends CatalogEntity
{
    public function songs()
    {
        return $this->belongsToMany(Song::class, 'song_artist');
    }

    public function albums()
    {
        return $this->belongsToMany(Album::class, 'album_artist');
    }
}
