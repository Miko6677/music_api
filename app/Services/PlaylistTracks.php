<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Facades\DB;

class PlaylistTracks
{
    public function songs($entries, Models\User $user): array
    {
        $ids = $entries->pluck('media_file_id')->unique();
        $media = Models\Server\Record::at('media_file')->select(['id', 'pid', 'title'])->whereIn('id', $ids)->get()->keyBy('id');
        $resolved = $ids->mapWithKeys(fn($id) => [$id => $media->get($id)?->pid ?: $id]);
        for ($depth = 0; $depth < 32; $depth++) {
            $aliases = Models\Server\Record::at('music_song_alias')->select(['source_id', 'target_id'])->whereIn('source_id', $resolved->values())->get()->pluck('target_id', 'source_id');
            if ($aliases->isEmpty()) {
                break;
            }
            $resolved = $resolved->map(fn($id) => $aliases->get($id, $id));
        }
        $songIds = $resolved->map(fn($id) => preg_match('/^music-([0-9a-f-]{36})$/i', $id, $match) ? $match[1] : null)->filter();
        $songs = Models\Song::withTrashed()->select(Models\Song::LIST_FIELDS)->whereIn('id', $songIds)->with(['artists:id,name', 'albums:id,name,cover_id,image_url', 'library:id,name'])->withCount('files')->withExists(['fans as is_favorite' => fn($q) => $q->where('users.id', $user->id)])->get()->keyBy('id');

        return $entries->map(function ($entry) use ($songs, $songIds, $media) {
            $song = $songs->get($songIds->get($entry->media_file_id));
            $data = $song?->toArray() ?? ['id' => $songIds->get($entry->media_file_id, $entry->media_file_id), 'name' => $media->get($entry->media_file_id)?->title ?? '暂不可用的歌曲', 'files_count' => 0];

            return [...$data, 'entry_id' => (string)$entry->id, 'available' => $song && !$song->trashed()];
        })->all();
    }

    public function append(Models\Server\Playlist $playlist, array $songIds): void
    {
        $key = (int)$playlist->entries()->max('sort_key');
        foreach (array_chunk($songIds, 200) as $chunk) {
            $rows = [];
            foreach ($chunk as $songId) {
                $key += Models\Server\PlaylistTrack::SORT_GAP;
                $rows[] = ['playlist_id' => $playlist->id, 'media_file_id' => 'music-' . $songId, 'sort_key' => $key];
            }
            Models\Server\PlaylistTrack::insert($rows);
        }
        $this->refresh($playlist);
    }

    public function refresh(Models\Server\Playlist $playlist): void
    {
        $stats = Models\Server\Record::at('media_file')->join('playlist_tracks', 'playlist_tracks.media_file_id', '=', 'media_file.id')->where('playlist_tracks.playlist_id', $playlist->id)->where('media_file.missing', false)->selectRaw('count(*) AS song_count, coalesce(sum(media_file.duration), 0) AS duration, coalesce(sum(media_file.size), 0) AS size')->first();
        $playlist->fill($stats->getAttributes())->save();
        $playlist->touch();
    }

    public function remove(Models\Server\Playlist $playlist, array $entryIds): void
    {
        $playlist->entries()->whereIn('id', $entryIds)->delete();
        $this->refresh($playlist);
    }

    public function move(Models\Server\Playlist $playlist, string $entryId, int $position): void
    {
        $entry = $playlist->entries()->select(Models\Server\PlaylistTrack::FIELDS)->findOrFail($entryId);
        $position = min($position, $playlist->entries()->count());
        $key = $this->keyAt($playlist, $entry->id, $position);
        if ($key === null) {
            // Rebalance only when repeated insertions have exhausted an integer gap.
            DB::connection('music')->update('WITH ranked AS (SELECT id, row_number() OVER (ORDER BY sort_key, id) * ? AS sort_key FROM playlist_tracks WHERE playlist_id = ?) UPDATE playlist_tracks t SET sort_key = ranked.sort_key FROM ranked WHERE t.id = ranked.id', [Models\Server\PlaylistTrack::SORT_GAP, $playlist->id]);
            $key = $this->keyAt($playlist, $entry->id, $position);
        }
        $entry->update(['sort_key' => $key]);
        $playlist->touch();
    }

    private function keyAt(Models\Server\Playlist $playlist, int $entryId, int $position): ?int
    {
        $neighbors = $playlist->entries()->whereKeyNot($entryId)->ordered()->skip(max(0, $position - 2))->take($position === 1 ? 1 : 2)->pluck('sort_key');
        $before = $position === 1 ? 0 : (int)($neighbors[0] ?? 0);
        $after = $neighbors[$position === 1 ? 0 : 1] ?? null;
        if ($after === null) {
            return $before + Models\Server\PlaylistTrack::SORT_GAP;
        }

        return $after - $before > 1 ? $before + intdiv($after - $before, 2) : null;
    }

    public function refreshAllStats(?array $mediaIds = null): void
    {
        $stats = Models\Server\Playlist::query()->leftJoin('playlist_tracks', 'playlist_tracks.playlist_id', '=', 'playlist.id')
            ->leftJoin('media_file', fn($join) => $join->on('media_file.id', '=', 'playlist_tracks.media_file_id')->where('media_file.missing', false))
            ->select(['playlist.id', 'playlist.song_count', 'playlist.duration', 'playlist.size'])
            ->selectRaw('count(media_file.id) AS next_count, coalesce(sum(media_file.duration), 0) AS next_duration, coalesce(sum(media_file.size), 0) AS next_size')
            ->groupBy('playlist.id');
        if ($mediaIds !== null) {
            $stats->whereIn('playlist.id', Models\Server\PlaylistTrack::select(['playlist_id'])->whereIn('media_file_id', $mediaIds));
        }
        foreach ($stats->cursor() as $row) {
            if ((int)$row->song_count === (int)$row->next_count && (float)$row->duration === (float)$row->next_duration && (int)$row->size === (int)$row->next_size) {
                continue;
            }
            Models\Server\Record::at('playlist')->whereKey($row->id)->update(['song_count' => $row->next_count, 'duration' => $row->next_duration, 'size' => $row->next_size]);
        }
    }
}
