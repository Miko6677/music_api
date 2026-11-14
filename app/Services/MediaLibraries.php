<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Facades\DB;

class MediaLibraries
{
    public function assign(Models\User $user, array $items, string $libraryId, ?string $reason = null): void
    {
        DB::transaction(function () use ($user, $items, $libraryId, $reason) {
            $songs = Models\Song::select(Models\Song::FIELDS)->whereIn('id', array_column($items, 'id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            Models\MediaLibrary::select(['id'])->lockForUpdate()->findOrFail($libraryId);
            abort_unless($songs->count() === count($items), 404, '部分歌曲已被删除，请刷新。');
            $changed = false;
            foreach ($items as $item) {
                $song = $songs[$item['id']];
                abort_if($song->version !== $item['version'], 409, '「' . $song->name . '」已更新，请刷新后核对。');
                if ($song->library_id === $libraryId) {
                    continue;
                }
                $before = app(Activity::class)->snapshot($song);
                $changed = true;
                $song->library_id = $libraryId;
                $song->version++;
                $song->save();
                app(Activity::class)->record($user, 'updated', 'songs', $song, $before, $reason ?: '调整歌曲所属媒体库');
            }
            if ($changed) {
                app(SyncScheduler::class)->touch();
            }
        }, 3);
    }
}
