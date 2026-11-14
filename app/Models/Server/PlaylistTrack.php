<?php

namespace App\Models\Server;

use App\Models;

class PlaylistTrack extends Models\Base
{
    const FIELDS   = ['id', 'playlist_id', 'media_file_id', 'sort_key'];
    const SORT_GAP = 1048576;
    public $timestamps = false;
    protected $connection = 'music';
    protected $table = 'playlist_tracks';

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_key')->orderBy('id');
    }

    protected function casts(): array
    {
        return ['id' => 'integer', 'sort_key' => 'integer'];
    }
}
