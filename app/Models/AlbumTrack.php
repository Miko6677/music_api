<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AlbumTrack extends Base
{
    use HasUuids;

    const FIELDS = ['id', 'album_id', 'song_id', 'disc_number', 'track_number', 'version', 'created_at', 'updated_at'];
    protected $table = 'album_song';

    public function song()
    {
        return $this->belongsTo(Song::class);
    }

    public function album()
    {
        return $this->belongsTo(Album::class);
    }
}
