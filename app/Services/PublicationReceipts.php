<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class PublicationReceipts
{
    public function hash(array $data): string
    {
        $data = Arr::except($data, ['file_id']);
        if (isset($data['album_supplement'])) {
            // A refreshed editor may have a newer optimistic version or a new local draft ID.
            // Neither changes the requested supplement.
            $data['album_supplement'] = Arr::only($data['album_supplement'], ['cover_id', 'replace_cover', 'artwork', ...Models\Album::EDITION_FIELDS]);
        }
        $sort = function (array $value) use (&$sort): array {
            if (!array_is_list($value)) {
                ksort($value);
            }
            return array_map(fn($item) => is_array($item) ? $sort($item) : $item, $value);
        };

        return hash('sha256', json_encode($sort($data), JSON_THROW_ON_ERROR));
    }

    public function replay(Models\User $user, Models\StoredFile $file, array $data): ?array
    {
        $receipt = $file->publication;
        if (!$receipt || $receipt['user_id'] !== $user->id || $receipt['request_hash'] !== $this->hash($data)) {
            return null;
        }
        $result = $receipt['result'];
        abort_unless($file->song_id === $result['song_id'] && Models\Song::whereKey($result['song_id'])->exists(), 409, '已入库的音频后来有调整，请刷新后核对。');
        if ($result['album_id']) {
            abort_unless(Models\AlbumTrack::where('song_id', $result['song_id'])->where('album_id', $result['album_id'])
                ->where('disc_number', $receipt['disc_number'])->where('track_number', $receipt['track_number'])->whereHas('album')->exists(), 409, '已入库的专辑收录后来有调整，请刷新后核对。');
        }

        return [...$result, 'file' => $file];
    }

    public function trackKey(Models\User $user, array $data): ?string
    {
        $album = $data['album_id'] ?? $data['album']['id'] ?? null;
        if (!$album || !empty($data['song_id']) || empty($data['publication_group'])) {
            return null;
        }
        $artists = $data['artist_ids'] ?? [];
        sort($artists);

        return $this->hash(['user_id' => $user->id, 'group' => $data['publication_group'], 'album' => $album,
            'library' => $data['library_id'] ?? Models\MediaLibrary::DEFAULT_ID, 'disc' => $data['disc_number'] ?? 1,
            'track' => $data['track_number'] ?? 1, 'name' => trim($data['name'] ?? ''), 'artists' => $artists,
            'artist_name' => trim($data['artist_name'] ?? ''), 'version' => trim($data['recording_version'] ?? ''),
            'recording_song_id' => $data['recording_song_id'] ?? null]);
    }

    public function resolve(Models\User $user, Models\StoredFile $file, array $data, ?string $key): array
    {
        if (!$key) {
            return $data;
        }
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['publication-'.$key]);
        $previous = Models\StoredFile::select(['id', 'song_id', 'duration', 'publication'])->where('user_id', $user->id)
            ->where('publication->track_key', $key)->whereNotNull('song_id')->orderBy('id')->first();
        if (!$previous) {
            return $data;
        }
        abort_unless($previous->song_id === $previous->publication['result']['song_id'] && Models\Song::whereKey($previous->song_id)->exists(), 409, '这一批的已入库歌曲后来有调整，请刷新后核对。');
        abort_if(abs($file->duration - $previous->duration) > max(5, $file->duration * .02), 422, '同一曲序的文件时长不同，请核对录音版本。');
        $data['song_id'] = $previous->song_id;
        unset($data['recording_song_id']);

        return $data;
    }

    public function remember(Models\User $user, Models\StoredFile $file, array $request, ?string $key, Models\Song $song, ?string $albumId): array
    {
        $result = ['song_id' => $song->id, 'album_id' => $albumId, 'library_id' => $song->library_id];
        $track = $albumId ? Models\AlbumTrack::select(['disc_number', 'track_number'])->where('song_id', $song->id)->where('album_id', $albumId)->firstOrFail() : null;
        $file->publication = ['user_id' => $user->id, 'request_hash' => $this->hash($request), 'track_key' => $key,
            'result' => $result, 'disc_number' => $track?->disc_number, 'track_number' => $track?->track_number];
        $file->save();

        return [...$result, 'file' => $file];
    }
}
