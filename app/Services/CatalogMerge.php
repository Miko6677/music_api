<?php

namespace App\Services;

use App\Models;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogMerge
{
    public function merge(Request $request, string $type, string $id): void
    {
        $class = app(Catalog::class)->model($type);
        $data = $request->validate(['target_id' => 'required|uuid', 'version' => 'required|integer', 'target_version' => 'required|integer', 'reason' => 'required|string|max:1000']);
        abort_if($id === $data['target_id'], 422, '请选择另一份资料。');
        DB::transaction(function () use ($request, $type, $id, $class, $data) {
            $rows = $class::select($class::FIELDS)->whereIn('id', [$id, $data['target_id']])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $rows->get($id);
            $target = $rows->get($data['target_id']);
            abort_unless($source && $target, 404);
            abort_if($source->version !== $data['version'] || $target->version !== $data['target_version'], 409, '资料刚有更新，请刷新后重新核对。');
            $activity = app(Activity::class);
            $before = $activity->snapshot($source);
            $graph = ['association_format' => 2, 'target_id' => $target->id, 'target_before' => $activity->snapshot($target)];
            if ($source instanceof Models\Song) {
                $sourceEntries = app(AlbumTracks::class)->entries($source);
                $targetEntries = app(AlbumTracks::class)->entries($target);
                abort_if(array_intersect(array_column($sourceEntries, 'album_id'), array_column($targetEntries, 'album_id')), 422, '两首歌在同一张专辑中都有曲序，请先移除重复的收录再合并。');
                app(AlbumTracks::class)->syncSong($source, []);
                app(AlbumTracks::class)->syncSong($target, [...$targetEntries, ...$sourceEntries], true);
                $lyrics = app(Lyrics::class);
                $lyrics->replace($target, $lyrics->merge($lyrics->versions($target), $lyrics->versions($source)));
                $resources = $source->files()->select(['id', 'is_default', 'updated_at'])->orderBy('id')->lockForUpdate()->get();
                $graph['resources'] = $resources->map(fn($f) => $f->only(['id', 'is_default', 'updated_at']))->all();
                $graph['target_default_before'] = $target->files()->where('is_default', true)->value('id');
                $source->files()->update(['is_default' => false]);
                $source->files()->update(['song_id' => $target->id]);
                Quality::ensureDefault($target);
                $graph['resources_after'] = Models\StoredFile::select(['id', 'updated_at'])->whereIn('id', $resources->pluck('id'))->get()->map(fn($f) => $f->only(['id', 'updated_at']))->all();
                $graph['target_default_after'] = $target->files()->where('is_default', true)->value('id');
                $graph['favorites'] = [];
                foreach (DB::table('favorites')->where('song_id', $id)->orderBy('user_id')->lockForUpdate()->get(['user_id']) as $favorite) {
                    $existing = DB::table('favorites')->where('song_id', $target->id)->where('user_id', $favorite->user_id)->first(['created_at']);
                    if (!$existing) {
                        DB::table('favorites')->insertOrIgnore(['song_id' => $target->id, 'user_id' => $favorite->user_id, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    $graph['favorites'][] = ['user_id' => $favorite->user_id, 'existed' => (bool)$existing, 'created_at' => DB::table('favorites')->where('song_id', $target->id)->where('user_id', $favorite->user_id)->value('created_at')];
                }
                DB::table('favorites')->where('song_id', $id)->delete();
            } elseif ($source instanceof Models\Album) {
                $graph['source_tracks'] = Models\AlbumTrack::select(Models\AlbumTrack::FIELDS)->where('album_id', $source->id)->orderBy('id')->lockForUpdate()->get()->toArray();
                $graph['target_tracks'] = Models\AlbumTrack::select(Models\AlbumTrack::FIELDS)->where('album_id', $target->id)->orderBy('id')->lockForUpdate()->get()->toArray();
                Models\Song::select(['id'])->whereIn('id', array_unique([...array_column($graph['source_tracks'], 'song_id'), ...array_column($graph['target_tracks'], 'song_id')]))->orderBy('id')->lockForUpdate()->get();
                $targetSongs = array_column($graph['target_tracks'], 'song_id');
                foreach ($graph['source_tracks'] as $track) {
                    if (!in_array($track['song_id'], $targetSongs, true)) {
                        app(AlbumCatalog::class)->assertSlot($target->id, $track['disc_number'], $track['track_number']);
                    }
                }
                Models\AlbumTrack::where('album_id', $source->id)->delete();
                foreach ($graph['source_tracks'] as $track) {
                    if (!in_array($track['song_id'], $targetSongs, true)) {
                        Models\AlbumTrack::create([...$track, 'album_id' => $target->id, 'version' => $track['version'] + 1]);
                    }
                }
                $graph['tracks_after'] = Models\AlbumTrack::select(Models\AlbumTrack::FIELDS)->where('album_id', $target->id)->orderBy('id')->get()->toArray();
                $graph['song_versions'] = Models\Song::select(['id', 'version'])->whereIn('id', array_column($graph['source_tracks'], 'song_id'))->get()->pluck('version', 'id')->all();
                Models\Song::whereIn('id', array_keys($graph['song_versions']))->increment('version');
                foreach (['cover_id', 'image_url', 'release_date', 'genre', ...Models\Album::EDITION_FIELDS] as $field) {
                    if (blank($target->$field) && !blank($source->$field)) {
                        $target->$field = $source->$field;
                        if ($field === 'musicbrainz_id') {
                            $source->musicbrainz_id = null;
                            $source->save();
                        }
                    }
                }
                $target->artwork = collect([...($target->artwork ?? []), ...($source->artwork ?? [])])->unique('file_id')->values()->all();
            } else {
                foreach (['songs' => Models\Song::class, 'albums' => Models\Album::class] as $relation => $relatedClass) {
                    $graph[$relation] = [];
                    foreach ($source->$relation()->select(array_map(fn($f) => $relation . '.' . $f, $relatedClass::FIELDS))->orderBy($relation . '.id')->lockForUpdate()->get() as $entity) {
                        $graph[$relation][] = ['id' => $entity->id, 'version' => $entity->version, 'artist_ids' => $entity->artists()->pluck('artists.id')->all()];
                        $ids = array_values(array_unique(array_map(fn($aid) => $aid === $id ? $target->id : $aid, end($graph[$relation])['artist_ids'])));
                        $entity->artists()->sync($ids);
                        $entity->version++;
                        $entity->save();
                    }
                }
            }
            if ($type === 'songs' || ($type === 'albums' && !$target->artists()->exists())) {
                $target->artists()->syncWithoutDetaching($source->artists()->pluck('artists.id')->all());
            }
            if (!$target->todb_id && $source->todb_id) {
                $target->todb_id = $source->todb_id;
                $source->todb_id = null;
                $source->save();
            }
            $target->version++;
            $target->save();
            $source->version++;
            $source->save();
            $source->delete();
            $before['_merge'] = $graph;
            $activity->record($request->user(), 'merged', $type, $source, $before, $data['reason']);
            app(SyncScheduler::class)->touch();
        }, 3);
    }

    public function undo($source, array $graph): void
    {
        $class = $source::class;
        $target = $class::select($class::FIELDS)->lockForUpdate()->findOrFail($graph['target_id']);
        abort_if($target->version !== $graph['target_before']['version'] + 1, 409, '合并目标后来有更新，请手动整理。');
        if ($source instanceof Models\Song) {
            $ids = array_column($graph['resources'], 'id');
            $files = Models\StoredFile::select(['id', 'song_id', 'updated_at'])->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_if($files->count() !== count($ids), 409, '合并的资源有变化，请手动整理。');
            foreach ($graph['resources_after'] as $snapshot) {
                $file = $files->get($snapshot['id']);
                abort_if(!$file || $file->song_id !== $target->id || !$file->updated_at->equalTo($snapshot['updated_at']), 409, '合并的资源后来有变化，请手动整理。');
            }
            abort_if($target->files()->where('is_default', true)->value('id') !== $graph['target_default_after'], 409, '合并后的默认音质有变化，请手动整理。');
            Models\StoredFile::whereIn('id', $ids)->update(['is_default' => false, 'song_id' => $source->id]);
            foreach ($graph['resources'] as $f) {
                if ($f['is_default']) {
                    Models\StoredFile::whereKey($f['id'])->update(['is_default' => true]);
                }
            }
            foreach ($graph['favorites'] as $f) {
                $query = DB::table('favorites')->where('song_id', $target->id)->where('user_id', $f['user_id']);
                abort_if($query->value('created_at') !== $f['created_at'], 409, '收藏状态后来有变化，请手动整理。');
                if (!$f['existed']) {
                    $query->delete();
                }
                DB::table('favorites')->insertOrIgnore(['song_id' => $source->id, 'user_id' => $f['user_id'], 'created_at' => now(), 'updated_at' => now()]);
            }
            Quality::ensureDefault($source);
            Quality::ensureDefault($target);
        } elseif ($source instanceof Models\Album) {
            $current = Models\AlbumTrack::select(Models\AlbumTrack::FIELDS)->where('album_id', $target->id)->orderBy('id')->lockForUpdate()->get()->toArray();
            $shape = fn($tracks) => collect($tracks)->map(fn($t) => array_intersect_key($t, array_flip(['id', 'album_id', 'song_id', 'disc_number', 'track_number', 'version'])))->values()->all();
            abort_if($shape($current) !== $shape($graph['tracks_after']), 409, '合并后的专辑曲目已有调整，请手动整理。');
            foreach ($graph['song_versions'] as $sid => $version) {
                abort_if(Models\Song::whereKey($sid)->value('version') !== $version + 1, 409, '相关歌曲后来有更新，请手动整理。');
            }
            Models\AlbumTrack::whereIn('album_id', [$source->id, $target->id])->delete();
            foreach ([...$graph['source_tracks'], ...$graph['target_tracks']] as $track) {
                Models\AlbumTrack::create([...$track, 'version' => $track['version'] + 1]);
            }
            Models\Song::whereIn('id', array_keys($graph['song_versions']))->increment('version');
        } else {
            foreach (['songs' => Models\Song::class, 'albums' => Models\Album::class] as $relation => $relatedClass) {
                foreach ($graph[$relation] ?? [] as $snapshot) {
                    $e = $relatedClass::select($relatedClass::FIELDS)->lockForUpdate()->findOrFail($snapshot['id']);
                    abort_if($e->version !== $snapshot['version'] + 1, 409, '关联资料后来有更新，请手动整理。');
                    $e->artists()->sync($snapshot['artist_ids']);
                    $e->version++;
                    $e->save();
                }
            }
        }
        $target->todb_id = $graph['target_before']['todb_id'] ?? null;
        if ($source instanceof Models\Album) {
            $target->fill(array_intersect_key($graph['target_before'], array_flip(['cover_id', 'image_url', 'release_date', 'genre', ...Models\Album::EDITION_FIELDS, 'artwork'])));
        }
        if ($source instanceof Models\Song) {
            app(AlbumTracks::class)->syncSong($target, $graph['target_before']['albums'], true);
            app(Lyrics::class)->replace($target, $graph['target_before']['lyrics'] ?? []);
        }
        $target->version++;
        $target->save();
        if (method_exists($target, 'artists')) {
            $target->artists()->sync($graph['target_before']['artist_ids'] ?? []);
        }
        app(SyncScheduler::class)->merged(array_search($class, Catalog::TYPES, true), $graph);
    }
}
