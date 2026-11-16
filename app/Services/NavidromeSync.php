<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Facades\DB;

class NavidromeSync
{
    public function run(?array $changes = null): void
    {
        \Illuminate\Support\Facades\Cache::lock('music-catalog-sync', 660)->block(10, function () use ($changes) {
            $this->synchronize($changes);
            app(PlaybackAliases::class)->sync();
        });
    }

    private function synchronize(?array $changes): void
    {
        DB::transaction(function () use ($changes) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::connection('music')->transaction(function () use ($changes) {
                DB::connection('music')->select('SELECT pg_advisory_xact_lock(731142026)');
                $full = $changes === null || in_array('full', $changes, true);
                $groups = [];
                foreach ($changes ?? [] as $key) {
                    [$type, $id] = array_pad(explode(':', $key, 2), 2, '');
                    $groups[$type][] = $id;
                }
                $allLibraries = Models\MediaLibrary::withTrashed()->select(Models\MediaLibrary::FIELDS)->get();
                $libraries = $allLibraries->filter(fn($library) => !$library->trashed())->keyBy('id');
                $query = Models\Song::withTrashed()->select(['id']);
                if (!$full) {
                    $query->where(function ($songs) use ($groups) {
                        $songs->whereIn('id', $groups['songs'] ?? [])
                            ->orWhereHas('albums', fn($albums) => $albums->whereIn('albums.id', $groups['albums'] ?? []))
                            ->orWhereHas('artists', fn($artists) => $artists->whereIn('artists.id', $groups['artists'] ?? []))
                            ->orWhereHas('albums.artists', fn($artists) => $artists->whereIn('artists.id', $groups['artists'] ?? []));
                    });
                }
                $songIds = $query->pluck('id')->all();
                $canonicalIds = array_map(fn($id) => 'music-'.$id, $songIds);
                $oldQuery = $this->table('media_file')->select(['id', 'pid', 'album_id', 'library_id']);
                if ($full) {
                    $oldQuery->where('id', 'like', 'music-%');
                } else {
                    $oldQuery->where(fn($rows) => $rows->whereIn('pid', $canonicalIds)->orWhereIn('id', $canonicalIds));
                }
                $old = $oldQuery->get();
                $mediaIds = $old->pluck('id')->all();
                $albumIds = $old->pluck('album_id')->all();
                $libraryIds = $old->pluck('library_id')->all();
                foreach (array_chunk($mediaIds, 500) as $ids) {
                    $this->table('media_file')->whereIn('id', $ids)->update(['missing' => true]);
                    $this->table('media_file_artists')->whereIn('media_file_id', $ids)->delete();
                    $this->table('media_file_tags')->whereIn('media_file_id', $ids)->delete();
                    $this->table('music_cover')->whereIn('id', $ids)->delete();
                }
                foreach (array_chunk($canonicalIds, 500) as $ids) {
                    $this->table('music_resource')->whereIn('song_id', $ids)->delete();
                }
                if ($full) {
                    $albumIds = $this->table('album')->where(fn($q) => $q->where('id', 'like', 'music-%')->orWhere('id', 'like', 'single-%'))->pluck('id')->all();
                    $libraryIds = $allLibraries->pluck('playback_id')->all();
                    $this->syncLibraries($allLibraries);
                    foreach (Models\User::select(Models\User::SESSION_FIELDS)->cursor() as $user) {
                        app(ServerAccount::class)->sync($user, false);
                    }
                } else {
                    $changedLibraries = $allLibraries->whereIn('id', $groups['libraries'] ?? []);
                    $this->syncLibraries($changedLibraries);
                    $libraryIds = [...$libraryIds, ...$changedLibraries->pluck('playback_id')->all()];
                }
                $artists = Models\Artist::withTrashed()->select(Models\Artist::FIELDS);
                if (!$full) {
                    $artists->whereIn('id', $groups['artists'] ?? []);
                }
                foreach ($artists->cursor() as $artist) {
                    $id = 'music-'.$artist->id;
                    $this->put('artist', ['id' => $id, 'name' => $artist->name, 'order_artist_name' => mb_strtolower($artist->name), 'biography' => $artist->description,
                        'small_image_url' => $artist->image_url ?? '', 'medium_image_url' => $artist->image_url ?? '', 'large_image_url' => $artist->image_url ?? '',
                        'full_text' => $artist->name.' '.$artist->description, 'search_normalized' => $artist->name, 'missing' => $artist->trashed(), 'created_at' => $artist->created_at, 'updated_at' => $artist->updated_at]);
                    $this->syncCover($id, $artist->trashed() ? null : $artist->cover_id, $artist->trashed() ? null : $artist->image_url);
                }
                $tags = app(PlaybackTags::class);
                $songs = Models\Song::select(Models\Song::FIELDS)->whereIn('id', $songIds)->with([
                    'lyrics' => fn($q) => $q->select(['id', 'song_id', 'is_default', 'structured'])->where('is_default', true), 'artists:id,name',
                    'albums' => fn($q) => $q->select(array_map(fn($f) => 'albums.'.$f, Models\Album::FIELDS))->with('artists:id,name'),
                    'files' => fn($q) => $q->select(['id', 'song_id', 'user_id', 'file_name', 'file_path', 'mime_type', 'quality', 'is_default', 'bitrate', 'sample_rate', 'bit_depth', 'channels', 'codec', 'file_size', 'duration', 'created_at', 'updated_at'])->orderBy('created_at')->orderBy('id')->with('uploader:id,username,pseudonym')]);
                foreach ($songs->lazyById(200) as $song) {
                    $file = $song->files->firstWhere('is_default', true) ?? $song->files->first();
                    $library = $libraries->get($song->library_id);
                    if (!$file || !$library) {
                        continue;
                    }
                    $canonicalId = 'music-'.$song->id;
                    $libraryIds[] = $library->playback_id;
                    foreach ($song->albums->isEmpty() ? [null] : $song->albums->all() as $index => $album) {
                        $mediaId = $index === 0 ? $canonicalId : 'music-track-'.$album->pivot->id;
                        $albumIds[] = $this->albumId($song, $album, $library->playback_id);
                        $mediaIds[] = $mediaId;
                        $this->placement($song, $file, $album, $library->playback_id, $mediaId, $tags);
                        if ($mediaId !== $canonicalId) {
                            $this->table('music_song_alias')->upsert([['source_id' => $mediaId, 'target_id' => $canonicalId]], ['source_id'], ['target_id']);
                        }
                    }
                    $resources = $song->files->map(fn($resource) => ['id' => $resource->id, 'song_id' => $canonicalId, 'path' => $resource->file_path, 'mime_type' => $resource->mime_type,
                        'suffix' => strtolower(pathinfo($resource->file_name, PATHINFO_EXTENSION)), 'quality' => $resource->quality, 'is_default' => $resource->is_default,
                        'bitrate' => $resource->bitrate, 'sample_rate' => $resource->sample_rate, 'bit_depth' => $resource->bit_depth, 'channels' => $resource->channels,
                        'codec' => $resource->codec, 'size' => $resource->file_size, 'duration' => $resource->duration, 'created_at' => $resource->created_at])->all();
                    foreach (array_chunk($resources, 500) as $rows) {
                        $this->table('music_resource')->insert($rows);
                    }
                }
                app(PlaybackStatistics::class)->refresh($albumIds, $libraryIds);
                app(PlaylistTracks::class)->refreshAllStats(array_values(array_unique($mediaIds)));
            });
        });
    }

    private function albumId(Models\Song $song, ?Models\Album $album, int $libraryId): string
    {
        return $album ? 'music-'.$album->id.($libraryId === 1 ? '' : '-library-'.$libraryId) : 'single-'.$song->id;
    }

    private function syncLibraries($libraries): void
    {
        foreach ($libraries as $library) {
            if ($library->trashed()) {
                $this->table('user_library')->where('library_id', $library->playback_id)->delete();
                $this->table('library')->whereKey($library->playback_id)->update(['default_new_users' => false, 'total_songs' => 0, 'total_albums' => 0, 'total_artists' => 0, 'total_size' => 0, 'total_duration' => 0]);

                continue;
            }
            $path = $library->is_default ? '' : 'libraries/' . $library->id;
            $this->put('library', ['id' => $library->playback_id, 'name' => $library->name, 'path' => $path, 'default_new_users' => $library->is_default, 'created_at' => $library->created_at, 'updated_at' => $library->updated_at]);
        }
    }

    private function table(string $name): Models\Server\Record
    {
        return Models\Server\Record::at($name);
    }

    private function put(string $table, array $data): void
    {
        $this->table($table)->upsert([$data], ['id'], array_values(array_diff(array_keys($data), ['id', 'created_at'])));
    }

    private function syncCover(string $id, ?string $fileId, ?string $url): void
    {
        $file = $fileId ? Models\StoredFile::select(['file_path', 'mime_type'])->where('kind', 'cover')->find($fileId) : null;
        if (!$file && !$url) {
            $this->table('music_cover')->whereKey($id)->delete();
            return;
        }
        $this->table('music_cover')->upsert([['id' => $id, 'path' => $file?->file_path ?? '', 'mime_type' => $file?->mime_type ?? '', 'url' => $url ?? '']], ['id'], ['path', 'mime_type', 'url']);
    }

    private function placement(Models\Song $song, Models\StoredFile $file, ?Models\Album $album, int $libraryId, string $mediaId, PlaybackTags $tags): void
    {
        $albumId = $this->albumId($song, $album, $libraryId);
        $artist = $song->artists->first();
        $artistId = $artist ? 'music-' . $artist->id : 'music-unknown';
        $artistName = $song->artists->pluck('name')->implode(' / ') ?: '未知音乐人';
        if (!$artist) {
            $this->put('artist', ['id' => 'music-unknown', 'name' => '未知音乐人', 'order_artist_name' => '未知音乐人', 'missing' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
        $albumArtists = $album?->artists;
        $albumArtist = $albumArtists?->first();
        $albumArtistId = $albumArtist ? 'music-' . $albumArtist->id : $artistId;
        $albumArtistName = $albumArtists?->pluck('name')->implode(' / ') ?: $artistName;
        $albumName = $album?->name ?: '单曲 · ' . $song->name;
        $releaseDate = $album?->release_date ?: ($album?->release_year ? (string)$album->release_year : ($song->release_date ?? ''));
        $releaseYear = (int)substr($releaseDate, 0, 4);
        $participants = ['artist' => $song->artists->map(fn($a) => ['id' => 'music-' . $a->id, 'name' => $a->name])->all(), 'albumartist' => ($albumArtists?->count() ? $albumArtists : $song->artists)->map(fn($a) => ['id' => 'music-' . $a->id, 'name' => $a->name])->all()];
        $this->put('album', ['id' => $albumId, 'name' => $albumName, 'album_artist' => $albumArtistName, 'album_artist_id' => $albumArtistId, 'order_album_name' => mb_strtolower($albumName), 'order_album_artist_name' => mb_strtolower($albumArtistName), 'library_id' => $libraryId, 'missing' => false, 'description' => $album?->description ?? '', 'release_date' => $releaseDate, 'min_year' => $releaseYear, 'max_year' => $releaseYear, 'full_text' => $albumName . ' ' . $albumArtistName, 'search_normalized' => $albumName . ' ' . $albumArtistName, 'created_at' => $album?->created_at ?? $song->created_at, 'updated_at' => $album?->updated_at ?? $song->updated_at, 'participants' => json_encode(['albumartist' => $participants['albumartist']], JSON_UNESCAPED_UNICODE), 'imported_at' => $album?->created_at ?? $song->created_at]);
        $this->syncCover($albumId, $album?->cover_id ?? $song->cover_id, $album?->image_url ?? $song->image_url);
        $folderId = 'folder-' . $albumId;
        $this->put('folder', ['id' => $folderId, 'library_id' => $libraryId, 'name' => $albumName, 'path' => $albumId, 'updated_at' => $song->updated_at]);
        $genre = $song->genre ?: $album?->genre ?? '';
        $genreNames = array_values(array_filter(array_map('trim', preg_split('~[/,，;；]+~u', $genre))));
        $this->put('media_file', ['id' => $mediaId, 'path' => $file->file_path, 'title' => $song->name . ($song->recording_version ? ' (' . $song->recording_version . ')' : ''), 'comment' => $this->uploaderComment($song), 'album' => $albumName, 'artist' => $artistName, 'artist_id' => $artistId, 'album_id' => $albumId, 'album_artist' => $albumArtistName, 'album_artist_id' => $albumArtistId, 'track_number' => $album?->pivot->track_number ?? 1, 'disc_number' => $album?->pivot->disc_number ?? 1, 'year' => $releaseYear, 'size' => $file->file_size, 'suffix' => strtolower(pathinfo($file->file_name, PATHINFO_EXTENSION)), 'duration' => $file->duration, 'bit_rate' => $file->bitrate, 'sample_rate' => $file->sample_rate, 'bit_depth' => $file->bit_depth, 'channels' => $file->channels, 'codec' => $file->codec, 'genre' => $genre, 'library_id' => $libraryId, 'folder_id' => $folderId, 'pid' => 'music-' . $song->id, 'missing' => false, 'order_title' => mb_strtolower($song->name), 'order_artist_name' => mb_strtolower($artistName), 'order_album_name' => mb_strtolower($albumName), 'order_album_artist_name' => mb_strtolower($albumArtistName), 'full_text' => $song->name . ' ' . $artistName . ' ' . $albumName, 'search_normalized' => $song->name . ' ' . $artistName . ' ' . $albumName, 'created_at' => $song->created_at, 'updated_at' => max($song->updated_at, $file->updated_at), 'has_cover_art' => (bool)($song->cover_id || $song->image_url || $album?->cover_id || $album?->image_url), 'lyrics' => app(Lyrics::class)->playback($song), 'participants' => json_encode($participants, JSON_UNESCAPED_UNICODE), 'tags' => $tags->encode($genreNames)]);
        $tags->add($mediaId, $genre);
        $this->syncCover($mediaId, $song->cover_id ?? $album?->cover_id, $song->image_url ?? $album?->image_url);
        $this->table('media_file_artists')->where('media_file_id', $mediaId)->delete();
        $ids = $song->artists->pluck('id')->map(fn($i) => 'music-' . $i)->all() ?: ['music-unknown'];
        $albumIds = ($albumArtists?->count() ? $albumArtists : $song->artists)->pluck('id')->map(fn($i) => 'music-' . $i)->all() ?: ['music-unknown'];
        foreach (['artist' => $ids, 'albumartist' => $albumIds] as $role => $roleIds) {
            foreach ($roleIds as $aid) {
                $this->table('media_file_artists')->insertOrIgnore(['media_file_id' => $mediaId, 'artist_id' => $aid, 'role' => $role, 'sub_role' => '']);
            }
        }
    }

    private function uploaderComment(Models\Song $song): string
    {
        $labels = config('contribution.quality_labels');
        $uploaders = $song->files->groupBy('user_id')->map(function ($files) use ($labels) {
            $user = $files->first()->uploader;
            $name = $user?->pseudonym ?: ($user?->username ?: '用户');
            $qualities = $files->pluck('quality')->unique()->map(fn($quality) => $labels[$quality] ?? $quality)->implode('、');

            return $name . '（' . $qualities . '）';
        });

        return $uploaders->isEmpty() ? '' : '上传者：' . $uploaders->implode('、');
    }

}
