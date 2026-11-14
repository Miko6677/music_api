<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Facades\DB;

class AlbumTracks
{
    public function attach(Models\Song $song, string $albumId, ?int $disc = null, ?int $track = null): bool
    {
        $entries = collect($this->entries($song))->keyBy('album_id');
        $existing = $entries->get($albumId);
        $entries[$albumId] = ['album_id' => $albumId, 'disc_number' => $disc ?? $existing['disc_number'] ?? 1, 'track_number' => $track ?? $existing['track_number'] ?? 1];

        return $this->syncSong($song, $entries->values()->all());
    }

    public function entries(Models\Song $song): array
    {
        return $song->albumTracks()->select(Models\AlbumTrack::FIELDS)->orderBy('album_id')->get()->toArray();
    }

    public function syncSong(Models\Song $song, array $entries, bool $restore = false): bool
    {
        $old = $this->entries($song);
        $ids = array_column($entries, 'album_id');
        abort_if(count(array_unique($ids)) !== count($ids), 422, '同一首歌在一张专辑中只能设置一份曲序。');
        $albums = Models\Album::select(['id'])->whereIn('id', array_unique([...$ids, ...array_column($old, 'album_id')]))->orderBy('id')->lockForUpdate()->get();
        abort_unless(count(array_intersect($ids, $albums->pluck('id')->all())) === count($ids), 422, '部分专辑已被删除，请重新核对。');
        foreach ($entries as $entry) {
            app(AlbumCatalog::class)->assertSlot($entry['album_id'], $entry['disc_number'], $entry['track_number'], [$song->id]);
        }
        $shape = fn($rows) => collect($rows)->map(fn($r) => ['album_id' => $r['album_id'], 'disc_number' => (int)$r['disc_number'], 'track_number' => (int)$r['track_number']])->sortBy('album_id')->values()->all();
        if ($shape($old) === $shape($entries)) {
            return false;
        }
        $existing = collect($old)->keyBy('album_id');
        $song->albumTracks()->whereNotIn('album_id', $ids)->delete();
        foreach ($entries as $entry) {
            $previous = $existing->get($entry['album_id']);
            if ($previous && (int)$previous['disc_number'] === (int)$entry['disc_number'] && (int)$previous['track_number'] === (int)$entry['track_number']) {
                continue;
            }
            $attributes = ['album_id' => $entry['album_id'], 'song_id' => $song->id, 'disc_number' => $entry['disc_number'], 'track_number' => $entry['track_number'], 'version' => ($previous['version'] ?? 0) + 1];
            $id = $restore && !empty($entry['id']) ? $entry['id'] : ($previous['id'] ?? null);
            if ($previous && $id === $previous['id']) {
                Models\AlbumTrack::whereKey($id)->update($attributes);
            } else {
                if ($previous) {
                    Models\AlbumTrack::whereKey($previous['id'])->delete();
                }
                if ($id) {
                    $attributes['id'] = $id;
                }
                $attributes['created_at'] = $previous['created_at'] ?? $entry['created_at'] ?? now();
                Models\AlbumTrack::create($attributes);
            }
        }

        return true;
    }

    public function assign(Models\User $user, array $items, string $albumId, ?string $reason = null): void
    {
        DB::transaction(function () use ($user, $items, $albumId, $reason) {
            $ids = array_column($items, 'id');
            $songs = Models\Song::select(Models\Song::FIELDS)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($songs->count() === count($items), 404, '部分歌曲已被删除，请刷新。');
            Models\Album::select(['id'])->lockForUpdate()->findOrFail($albumId);
            $slots = [];
            $before = [];
            $changed = [];
            $previous = Models\AlbumTrack::select(Models\AlbumTrack::FIELDS)->where('album_id', $albumId)->whereIn('song_id', $ids)->lockForUpdate()->get()->keyBy('song_id');
            foreach ($items as $item) {
                $song = $songs[$item['id']];
                abort_if($song->version !== $item['version'], 409, '「' . $song->name . '」刚有更新，请刷新后核对。');
                $old = $previous->get($song->id);
                if (($item['remove'] ?? false) ? (bool)$old : (!$old || $old->disc_number !== $item['disc_number'] || $old->track_number !== $item['track_number'])) {
                    $changed[] = $song->id;
                    $before[$song->id] = app(Activity::class)->snapshot($song);
                }
                if (!($item['remove'] ?? false)) {
                    $slot = $item['disc_number'] . ':' . $item['track_number'];
                    abort_if(isset($slots[$slot]), 422, '所选曲目的碟号和曲序重复，请先核对。');
                    $slots[$slot] = true;
                    app(AlbumCatalog::class)->assertSlot($albumId, $item['disc_number'], $item['track_number'], $ids);
                }
            }
            if (!$changed) {
                return;
            }
            // Remove this batch's positions first so swapping two tracks is atomic.
            Models\AlbumTrack::where('album_id', $albumId)->whereIn('song_id', $changed)->delete();
            foreach ($items as $item) {
                $song = $songs[$item['id']];
                if (!in_array($song->id, $changed, true)) {
                    continue;
                }
                $old = $previous->get($song->id);
                if (!($item['remove'] ?? false)) {
                    Models\AlbumTrack::create(['id' => $old?->id, 'album_id' => $albumId, 'song_id' => $song->id, 'disc_number' => $item['disc_number'], 'track_number' => $item['track_number'], 'version' => ($old?->version ?? 0) + 1, 'created_at' => $old?->created_at ?? now()]);
                }
                $song->version++;
                $song->save();
                app(Activity::class)->record($user, 'updated', 'songs', $song, $before[$song->id], $reason ?: '整理专辑收录与曲序');
            }
            app(SyncScheduler::class)->touch();
        }, 3);
    }
}
