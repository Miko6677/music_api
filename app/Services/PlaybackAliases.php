<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Facades\DB;

class PlaybackAliases
{
    private const FIELDS = ['id', 'entity_id', 'before', 'playback_state', 'playback_snapshot'];
    private const ANNOTATION_FIELDS = ['user_id', 'item_id', 'item_type', 'play_count', 'play_date', 'rating', 'starred', 'starred_at', 'rated_at'];

    public function sync(): void
    {
        foreach (Models\ActivityLog::select(self::FIELDS)->whereIn('playback_state', ['pending', 'restoring'])->orderBy('id')->cursor() as $log) {
            DB::connection('music')->transaction(function () use ($log) {
                DB::connection('music')->select('SELECT pg_advisory_xact_lock(731142026)');
                if ($log->playback_state === 'restoring') {
                    $this->restoreRows($log);
                } else {
                    $this->apply($log);
                }
            });
            $log->update(['playback_state' => $log->playback_state === 'restoring' ? 'restored' : 'applied']);
        }
    }

    public function restore(int $logId): void
    {
        $log = Models\ActivityLog::select(self::FIELDS)->findOrFail($logId);
        abort_unless(in_array($log->playback_state, ['pending', 'applied', 'restoring'], true), 409, '这条合并没有完整的播放库恢复记录，请手动整理。');
        $log->update(['playback_state' => 'restoring']);
        $this->restoreRows($log);
    }

    private function table(string $name): Models\Server\Record
    {
        return Models\Server\Record::at($name);
    }

    private function ids(Models\ActivityLog $log): array
    {
        return ['music-'.$log->entity_id, 'music-'.$log->before['_merge']['target_id']];
    }

    private function annotations(string $id, ?array $users = null, bool $lock = false): array
    {
        $query = $this->table('annotation')->select(self::ANNOTATION_FIELDS)->where('item_id', $id)->where('item_type', 'media_file');
        if ($users !== null) {
            $query->whereIn('user_id', $users);
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        return $query->orderBy('user_id')->get()->map(fn($row) => $row->getAttributes())->all();
    }

    private function capture(string $from, string $to): array
    {
        $source = $this->annotations($from, lock: true);
        $target = $this->annotations($to, array_column($source, 'user_id'), true);
        $current = collect($target)->keyBy('user_id');
        $after = [];
        foreach ($source as $annotation) {
            $row = [...$annotation, 'item_id' => $to];
            if ($existing = $current->get($annotation['user_id'])) {
                $row['play_count'] = ($row['play_count'] ?? 0) + ($existing['play_count'] ?? 0);
                $row['starred'] = $row['starred'] || $existing['starred'];
                $row['rating'] = max($row['rating'] ?? 0, $existing['rating'] ?? 0);
                foreach (['play_date', 'starred_at', 'rated_at'] as $key) {
                    $row[$key] = max($row[$key] ?? '', $existing[$key] ?? '') ?: null;
                }
            }
            $after[] = $row;
        }
        return ['source_annotations' => $source, 'target_annotations' => $target, 'annotations_after' => $after,
            'entries' => $this->table('playlist_tracks')->select(['id', 'playlist_id', 'media_file_id', 'sort_key'])->where('media_file_id', $from)->orderBy('id')->lockForUpdate()->get()->map(fn($row) => $row->getAttributes())->all(),
            'scrobbles' => $this->table('scrobbles')->select(['id', 'media_file_id', 'user_id', 'submission_time'])->where('media_file_id', $from)->orderBy('id')->lockForUpdate()->get()->map(fn($row) => $row->getAttributes())->all()];
    }

    private function apply(Models\ActivityLog $log): void
    {
        [$from, $to] = $this->ids($log);
        if ($target = $this->table('music_song_alias')->where('source_id', $from)->value('target_id')) {
            abort_unless($target === $to && $log->playback_snapshot !== null, 409, '播放库的歌曲关联已有变化，请先核对。');
            return;
        }
        foreach ([$from, $to] as $id) {
            $this->table('media_file')->insertOrIgnore(['id' => $id, 'pid' => $id, 'missing' => true]);
        }
        // Commit the snapshot before changing the music database. The alias is the music-side commit marker.
        $snapshot = $this->capture($from, $to);
        $log->update(['playback_snapshot' => $snapshot]);
        foreach (array_chunk($snapshot['annotations_after'], 500) as $rows) {
            $this->table('annotation')->upsert($rows, ['user_id', 'item_id', 'item_type'], ['play_count', 'play_date', 'rating', 'starred', 'starred_at', 'rated_at']);
        }
        $this->table('annotation')->where('item_id', $from)->where('item_type', 'media_file')->delete();
        foreach (['entries' => 'playlist_tracks', 'scrobbles' => 'scrobbles'] as $key => $table) {
            foreach (array_chunk(array_column($snapshot[$key], 'id'), 500) as $ids) {
                $this->table($table)->whereIn('id', $ids)->update(['media_file_id' => $to]);
            }
        }
        $this->table('music_song_alias')->insert(['source_id' => $from, 'target_id' => $to]);
        app(PlaylistTracks::class)->refreshAllStats([$from, $to]);
    }

    private function same(array $left, array $right): bool
    {
        $sort = function (array $value) use (&$sort): array {
            if (!array_is_list($value)) {
                ksort($value);
            }
            return array_map(fn($item) => is_array($item) ? $sort($item) : $item, $value);
        };
        return $sort($left) === $sort($right);
    }

    private function restoreRows(Models\ActivityLog $log): void
    {
        [$from, $to] = $this->ids($log);
        $alias = $this->table('music_song_alias')->where('source_id', $from)->value('target_id');
        $snapshot = $log->playback_snapshot;
        if (!$alias) {
            // The merge never reached the music database, or a previous restore already committed.
            return;
        }
        abort_unless($alias === $to && $snapshot !== null, 409, '播放库的歌曲关联已有变化，请先核对。');
        $users = array_column($snapshot['source_annotations'], 'user_id');
        abort_unless($this->annotations($from, $users, true) === [] && $this->same($this->annotations($to, $users, true), $snapshot['annotations_after']), 409, '合并后的收藏、评分或播放次数已有变化，请手动整理，避免覆盖新记录。');
        $this->assertRows($snapshot, $to);
        $this->table('annotation')->whereIn('item_id', [$from, $to])->where('item_type', 'media_file')->whereIn('user_id', $users)->delete();
        foreach (array_chunk([...$snapshot['source_annotations'], ...$snapshot['target_annotations']], 500) as $rows) {
            $this->table('annotation')->insert($rows);
        }
        foreach (['entries' => 'playlist_tracks', 'scrobbles' => 'scrobbles'] as $key => $table) {
            foreach (array_chunk(array_column($snapshot[$key], 'id'), 500) as $ids) {
                $this->table($table)->whereIn('id', $ids)->update(['media_file_id' => $from]);
            }
        }
        $this->table('music_song_alias')->where('source_id', $from)->delete();
        app(PlaylistTracks::class)->refreshAllStats([$from, $to]);
    }

    private function assertRows(array $snapshot, string $mediaId): void
    {
        foreach (['entries' => 'playlist_tracks', 'scrobbles' => 'scrobbles'] as $key => $table) {
            foreach (array_chunk($snapshot[$key], 500) as $rows) {
                $expected = array_map(fn($row) => [...$row, 'media_file_id' => $mediaId], $rows);
                $actual = $this->table($table)->select(array_keys($rows[0]))->whereIn('id', array_column($rows, 'id'))->orderBy('id')->lockForUpdate()->get()->map(fn($row) => $row->getAttributes())->all();
                abort_unless($this->same($actual, $expected), 409, '合并后的歌单曲目或播放记录已有变化，请手动整理。');
            }
        }
    }
}
