<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class SongLyric extends Base
{
    use HasUuids;

    const FIELDS = ['id', 'song_id', 'user_id', 'source_file_id', 'name', 'format', 'kind', 'language', 'source', 'source_format', 'source_data', 'content', 'lrc', 'structured', 'is_default', 'sort_order', 'created_at', 'updated_at'];

    protected $hidden = ['song_id', 'source_data', 'sort_order', 'structured'];

    public function song()
    {
        return $this->belongsTo(Song::class);
    }

    public function sourceFile()
    {
        return $this->belongsTo(StoredFile::class, 'source_file_id')->withTrashed();
    }

    protected function casts(): array
    {
        return ['source_data' => 'array', 'structured' => 'array', 'is_default' => 'boolean', 'sort_order' => 'integer', 'user_id' => 'integer'];
    }
}
