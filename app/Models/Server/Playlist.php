<?php

namespace App\Models\Server;

use App\Models;
use Illuminate\Database\Eloquent\SoftDeletes;

class Playlist extends Models\Base
{
    use SoftDeletes;

    const FIELDS = ['id', 'owner_id', 'name', 'comment', 'public', 'cover_id', 'song_count', 'duration', 'rules', 'sync', 'created_at', 'updated_at', 'deleted_at'];
    public $incrementing = false;
    protected $connection = 'music';
    protected $table = 'playlist';
    protected $keyType = 'string';
    protected $attributes = ['comment' => '', 'public' => true, 'cover_id' => null];

    public function entries()
    {
        return $this->hasMany(PlaylistTrack::class, 'playlist_id');
    }

    public function owner()
    {
        return $this->belongsTo(Account::class, 'owner_id');
    }

    public function scopeParticipating($query, string $accountId)
    {
        return $query->where(fn($q) => $q->where('owner_id', $accountId)->orWhereHas('collaborators', fn($c) => $c->where('app_user.id', $accountId)));
    }

    public function scopeVisibleTo($query, string $accountId)
    {
        return $query->where(fn($q) => $q->where('public', true)->orWhere(fn($p) => $p->participating($accountId)));
    }

    public function canEditTracks(string $accountId): bool
    {
        return !$this->rules && !$this->sync && ($this->owner_id === $accountId || $this->collaborators()->where('app_user.id', $accountId)->exists());
    }

    public function collaborators()
    {
        return $this->belongsToMany(Account::class, 'playlist_collaborator', 'playlist_id', 'user_id');
    }

    protected function casts(): array
    {
        return ['public' => 'boolean', 'sync' => 'boolean', 'song_count' => 'integer', 'duration' => 'float'];
    }
}
