<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;

class MediaLibrary extends CatalogEntity
{
    public const DEFAULT_ID = '00000000-0000-4000-8000-000000000001';
    const        FIELDS     = ['id', 'name', 'description', 'image_url', 'cover_id', 'user_id', 'version', 'playback_id', 'created_at', 'updated_at', 'deleted_at'];
    protected $table = 'libraries';
    protected $hidden = ['playback_id'];

    protected $appends = ['is_default'];

    public static function ensureDefault(User $user): self
    {
        return DB::transaction(function () use ($user) {
            DB::select('SELECT pg_advisory_xact_lock(731142027)');

            $library = static::select(self::FIELDS)->firstOrCreate(['id' => self::DEFAULT_ID], ['name' => '默认媒体库', 'user_id' => $user->id]);
            if ($library->wasRecentlyCreated) {
                app(\App\Services\SyncScheduler::class)->mark('libraries', $library->id);
                app(\App\Services\SyncScheduler::class)->touch();
            }
            return $library;
        });
    }

    protected static function booted(): void
    {
        static::creating(function (MediaLibrary $library) {
            $library->playback_id = $library->id === self::DEFAULT_ID ? 1 : DB::connection('music')->selectOne("SELECT nextval(pg_get_serial_sequence('library', 'id')) AS id")->id;
            abort_unless($library->playback_id, 503, '播放媒体库尚未初始化。');
        });
    }

    public function getIsDefaultAttribute(): bool
    {
        return $this->id === self::DEFAULT_ID;
    }

    public function songs()
    {
        return $this->hasMany(Song::class, 'library_id');
    }
}
