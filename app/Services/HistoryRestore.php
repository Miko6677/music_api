<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class HistoryRestore
{
    const TYPES = ['artists' => Models\Artist::class, 'albums' => Models\Album::class, 'songs' => Models\Song::class, 'files' => Models\StoredFile::class, 'libraries' => Models\MediaLibrary::class];

    public function restore(Models\User $user, int $id): void
    {
        $entry = Models\ActivityLog::select(['id', 'action', 'entity_type'])->findOrFail($id);
        if ($entry->action !== 'merged' || $entry->entity_type !== 'songs') {
            $this->restoreCatalog($user, $id);
            return;
        }
        // The journal commits with the catalog; a failed music commit can be retried by the worker.
        Cache::lock('music-catalog-sync', 660)->block(10, function () use ($user, $id) {
            DB::connection('music')->transaction(function () use ($user, $id) {
                DB::connection('music')->select('SELECT pg_advisory_xact_lock(731142026)');
                $this->restoreCatalog($user, $id);
            });
        });
    }

    private function restoreCatalog(Models\User $user, int $id): void
    {
        DB::transaction(function () use ($user, $id) {
            $log = Models\ActivityLog::select(Models\ActivityLog::FIELDS)->lockForUpdate()->findOrFail($id);
            if ($log->action === 'restored' && $log->restored_from) {
                abort_if(Models\ActivityLog::whereKey($log->restored_from)->where('action', 'merged')->exists(), 422, '这条记录撤销了合并。如需再次合并，请到资料页重新操作。');
            }
            abort_if($log->entity_type === 'libraries' && !$user->isAdmin(), 403, '只有管理员可以恢复媒体库。');
            abort_unless($log->before && isset(self::TYPES[$log->entity_type]), 422, '这条记录没有可恢复的旧版本。');
            abort_if(Models\ActivityLog::where('restored_from', $id)->exists(), 409, '这条记录已经恢复过了。');
            $class = self::TYPES[$log->entity_type];
            $e = $class::withTrashed()->select($class::FIELDS)->lockForUpdate()->findOrFail($log->entity_id);
            if ($e instanceof Models\StoredFile) {
                abort_unless($e->user_id === $user->id || $user->canModerate(), 403, '只有上传者、编辑或管理员可以恢复资源。');
            } else {
                abort_unless($log->user_id === $user->id || $user->canModerate(), 403, '只有操作人、编辑或管理员可以恢复资料。');
            }
            $latest = Models\ActivityLog::where('entity_type', $log->entity_type)->where('entity_id', $log->entity_id)->max('id');
            abort_if($latest !== $log->id, 409, '该条目有更新的操作，请先查看最新记录。');
            $current = app(Activity::class)->snapshot($e);
            $old = $log->before;
            abort_if(!($e instanceof Models\StoredFile) && isset($log->after['version']) && $e->version !== $log->after['version'], 409, '资料已更新，请查看当前版本后再操作。');
            if ($log->action === 'merged') {
                abort_if(($old['_merge']['association_format'] ?? null) !== 2, 422, '这条合并记录早于专辑关联结构更新，请在资料页整理当前关联。');
                app(CatalogMerge::class)->undo($e, $old['_merge']);
            }
            if ($e instanceof Models\StoredFile) {
                if (!empty($old['song_id'])) {
                    abort_unless(Models\Song::whereKey($old['song_id'])->exists(), 409, '原歌曲已被删除，先恢复歌曲。');
                }
                $defaults = $old['_defaults'] ?? [];
                foreach ($log->after['_defaults'] ?? [] as $sid => $expected) {
                    $actual = Models\StoredFile::where('song_id', $sid)->where('is_default', true)->value('id');
                    abort_if($actual !== $expected, 409, '这首歌的默认音质后来有变化，请先核对。');
                }
                if (!empty($old['song_id'])) {
                    abort_if(Models\StoredFile::where('song_id', $old['song_id'])->where('sha256', $e->getRawOriginal('sha256'))->whereKeyNot($e->id)->exists(), 409, '原歌曲已有相同文件，请先手动整理。');
                }
                Models\StoredFile::whereIn('song_id', array_keys($defaults))->update(['is_default' => false]);
                $e->fill(['song_id' => $old['song_id'] ?? null, 'is_default' => false]);
                $e->deleted_at = $old['deleted_at'] ?? null;
                $e->save();
                foreach ($defaults as $sid => $defaultId) {
                    if ($defaultId) {
                        $default = Models\StoredFile::select(['id'])->where('song_id', $sid)->find($defaultId);
                        abort_unless($default, 409, '原默认音质已变化，请手动整理。');
                        $default->update(['is_default' => true]);
                    }
                    if ($song = Models\Song::select(['id'])->find($sid)) {
                        Quality::ensureDefault($song);
                    }
                }

            } else {
                if ($e instanceof Models\Song) {
                    abort_unless(Models\MediaLibrary::whereKey($old['library_id'])->exists(), 409, '原媒体库已被删除，请先恢复媒体库。');
                    app(AlbumTracks::class)->syncSong($e, $old['albums'] ?? [], true);
                    app(Lyrics::class)->replace($e, $old['lyrics'] ?? []);
                }
                foreach ($old['artist_ids'] ?? [] as $aid) {
                    abort_unless(Models\Artist::whereKey($aid)->exists(), 409, '原音乐人已被删除，先恢复音乐人。');
                }
                $allowed = array_diff($class::FIELDS, ['id', 'user_id', 'version', 'created_at', 'updated_at', 'metadata_updated_at']);
                $e->fill(array_intersect_key($old, array_flip($allowed)));
                $e->version++;
                $e->save();
                if (method_exists($e, 'artists')) {
                    $e->artists()->sync($old['artist_ids'] ?? []);
                }

            }
            if ($log->action === 'merged' && $e instanceof Models\Song) {
                app(PlaybackAliases::class)->restore($id);
            }
            app(Activity::class)->record($user, 'restored', $log->entity_type, $e, $current, '恢复操作 #' . $id, $id);
            app(SyncScheduler::class)->touch();
        });
    }
}
