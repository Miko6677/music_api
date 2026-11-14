<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Facades\DB;

class ArtistNames
{
    public function resolve(Models\User $user, array $ids = [], ?string $name = null): array
    {
        if ($ids || blank($name)) {
            return $ids;
        }
        $name = trim($name);
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['artist-' . $name]);
        $matches = Models\Artist::select(['id'])->where('name', $name)->limit(2)->get();
        abort_if($matches->count() > 1, 422, '有多位同名音乐人，请选择具体的音乐人。');
        $artist = $matches->first();
        if (!$artist) {
            $artist = Models\Artist::create(['name' => $name, 'user_id' => $user->id]);
            app(Activity::class)->record($user, 'created', 'artists', $artist);
        }

        return [$artist->id];
    }
}
