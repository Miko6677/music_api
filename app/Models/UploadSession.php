<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class UploadSession extends Base
{
    use HasUuids;

    const FIELDS = ['id', 'user_id', 'song_id', 'kind', 'file_name', 'file_size', 'sha256', 'token_hash', 'tus_id', 'status', 'error', 'file_id', 'context', 'prepared_lyrics', 'expires_at'];

    protected $hidden = ['token_hash', 'prepared_lyrics'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'context' => 'array', 'prepared_lyrics' => 'array'];
    }
}
