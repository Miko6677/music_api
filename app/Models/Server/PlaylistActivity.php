<?php

namespace App\Models\Server;

use App\Models;

class PlaylistActivity extends Models\Base
{
    const FIELDS = ['id', 'playlist_id', 'actor_id', 'action', 'title', 'before', 'after', 'is_private', 'created_at'];
    public $timestamps = false;
    protected $connection = 'music';
    protected $table = 'playlist_activity';

    public function playlist()
    {
        return $this->belongsTo(Playlist::class, 'playlist_id')->withTrashed();
    }

    public function actor()
    {
        return $this->belongsTo(Account::class, 'actor_id');
    }

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'is_private' => 'boolean', 'created_at' => 'datetime'];
    }
}
