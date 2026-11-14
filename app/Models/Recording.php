<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Recording extends Base
{
    use HasUuids;

    const FIELDS = ['id', 'name', 'recording_version', 'user_id', 'created_at', 'updated_at'];

    public function songs()
    {
        return $this->hasMany(Song::class);
    }
}
