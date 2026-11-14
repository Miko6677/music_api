<?php

namespace App\Console\Commands;

use App\Models;
use App\Services;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SyncEmosMusic extends Command
{
    protected $signature = 'sync:emos-music {action : export 导出旧库 / import 导入 JSON}';

    protected $description = '临时迁移 emos_api 音乐数据，文件为 storage/app/emos_music.json';

    public function handle(): int
    {
        try {
            $path = storage_path('app/emos_music.json');
            switch ($this->argument('action')) {
                case 'export':
                    $data = $this->export();
                    File::ensureDirectoryExists(dirname($path));
                    File::replace($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                    $this->info('已导出：' . $path);
                    break;
                case 'import':
                    $data = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
                    if (($data['format'] ?? null) !== 'emos-music-1' || !is_array($data['tables'] ?? null)) {
                        throw new \RuntimeException('不是本命令导出的音乐 JSON。');
                    }
                    foreach (['users', 'media', 'songs', 'albums', 'tracks', 'persons', 'lyrics', 'favorites'] as $table) {
                        if (!isset($data['tables'][$table]) || !is_array($data['tables'][$table])) {
                            throw new \RuntimeException('JSON 缺少数据：' . $table);
                        }
                    }
                    if (empty($data['tables']['media'])) {
                        throw new \RuntimeException('JSON 中没有音频，已停止导入。');
                    }
                    $database = DB::connection()->getDatabaseName();
                    $this->warn("将清空 {$database} 中所有表的数据（保留 migrations 迁移记录），再导入音乐数据。");
                    if (!$this->input->isInteractive() || !$this->confirm('确认清空并导入？', false)) {
                        $this->info('已取消导入。');

                        return self::FAILURE;
                    }
                    $this->import($data['tables']);
                    $this->info('导入完成；已安排播放库同步。');
                    break;
                default:
                    $this->error('action 只能是 export 或 import。');

                    return self::FAILURE;
            }
            $this->table(['用户', '音频', '歌曲', '专辑', '音乐人关系', '歌词'], [[...array_map(fn($key) => count($data['tables'][$key]), ['users', 'media', 'songs', 'albums', 'persons', 'lyrics'])]]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function export(): array
    {
        config(['database.connections.emos_source' => ['driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => 3306, 'database' => 'emos_api', 'username' => 'root', 'password' => 'mariadb', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '']]);
        $source = fn($table) => (new Models\Base)->setConnection('emos_source')->setTable($table)->newQuery();
        $rows = fn($query, $fields) => $query->select($fields)->orderBy($query->getModel()->getTable() . '.id')->get()->map(fn($row) => $row->getAttributes())->all();

        return DB::connection('emos_source')->transaction(function () use ($source, $rows) {
            $media = $source('music_media')->whereNull('deleted_at');
            $songIds = (clone $media)->select(['music_song_id']);
            $userIds = (clone $media)->select(['user_id']);
            $songs = $source('music_songs')->whereIn('id', $songIds);
            $todbSongs = (clone $songs)->select(['todb_music_song_id'])->whereNotNull('todb_music_song_id');
            $tracks = $source('music_album_songs')->whereIn('todb_music_song_id', $todbSongs);
            $albums = $source('music_albums')->whereIn('todb_music_album_id', (clone $tracks)->select(['todb_music_album_id']));
            $todbAlbums = (clone $albums)->select(['todb_music_album_id']);
            // Include deleted upload history when finding each uploader's first contribution.
            $uploads = $source('music_media')->select(['user_id'])->selectRaw('MIN(created_at) AS first_uploaded_at')->whereIn('user_id', $userIds)->groupBy('user_id');

            return ['format' => 'emos-music-1', 'exported_at' => now()->toIso8601String(), 'tables' => [
                'users'     => $rows($source('users')->joinSub($uploads, 'uploads', 'users.id', '=', 'uploads.user_id'), ['users.id', 'emos_id', 'username', 'uploads.first_uploaded_at']),
                'media'     => $rows($media, ['id', 'music_song_id', 'file_type', 'file_suffix', 'file_size', 'file_hash', 'file_duration', 'file_metadata', 'file_quality', 'meta_rate_bit', 'meta_rate_sampling', 'meta_bit_depth', 'meta_channel', 'meta_bpm', 'path_type', 'path_url', 'is_primary', 'name', 'user_id', 'created_at', 'updated_at']),
                'songs'     => $rows($songs, ['id', 'todb_music_song_id', 'name', 'description', 'image_poster', 'tagline', 'release_date', 'duration', 'music_brainz_id', 'is_adult', 'created_at', 'updated_at', 'deleted_at']),
                'albums'    => $rows($albums, ['id', 'todb_music_album_id', 'name', 'description', 'image_poster', 'image_background', 'tagline', 'release_date', 'release_company', 'music_brainz_id', 'type', 'updated_at', 'deleted_at']),
                'tracks'    => $rows((clone $tracks)->whereIn('todb_music_album_id', $todbAlbums), ['id', 'todb_music_album_id', 'todb_music_song_id', 'disc_number', 'track_number', 'sort']),
                'persons'   => $rows($source('music_person')->where(fn($q) => $q->whereIn('todb_music_song_id', $todbSongs)->orWhereIn('todb_music_album_id', $todbAlbums)), ['id', 'todb_music_person_id', 'todb_music_song_id', 'todb_music_album_id', 'role', 'person_id', 'ext_id', 'name', 'original_name', 'image_profile', 'gender', 'is_virtual', 'is_adult', 'sort', 'updated_at']),
                'lyrics'    => $rows($source('music_lyric')->whereNull('deleted_at')->whereIn('music_song_id', $songIds), ['id', 'music_song_id', 'music_media_id', 'type', 'language', 'content', 'name', 'user_id', 'created_at', 'updated_at']),
                'favorites' => $rows($source('music_favorites')->where('relation_type', 'ms')->whereIn('relation_id', $songIds)->whereIn('user_id', $userIds), ['id', 'relation_id', 'user_id', 'updated_at']),
            ]];
        });
    }

    private function import(array $tables): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new \RuntimeException('导入目标必须是当前项目的 PostgreSQL 数据库。');
        }
        $owners = $sizes = $metadata = [];
        $parser = app(Services\AudioMetadata::class);
        $root = rtrim(config('filesystems.disks.music.root'), '/');
        $this->line('按转换后的路径请求 metadata；全部解析成功后才清空数据库。');
        $progress = $this->output->createProgressBar(count($tables['media']));
        foreach ($tables['media'] as $row) {
            if (!preg_match('/^[a-fA-F0-9]{64}$/', $row['file_hash'] ?? '')) {
                throw new \RuntimeException('音频 #' . $row['id'] . ' 缺少有效 SHA-256。');
            }
            $path = $root . '/' . $this->filePath($row['path_url']);
            try {
                $meta = $parser->parse($path);
                $metadata[$row['id']] = ['data' => $meta, 'cover' => $parser->cover($meta)];
            } catch (\Throwable $e) {
                throw new \RuntimeException('音频 #' . $row['id'] . ' ' . $path . '：' . $e->getMessage(), 0, $e);
            }
            $sizes[$row['user_id']] = ($sizes[$row['user_id']] ?? 0) + $row['file_size'];
            if (!isset($owners[$row['music_song_id']]) || $row['created_at'] < $owners[$row['music_song_id']]['created_at']) {
                $owners[$row['music_song_id']] = $row;
            }
            $progress->advance();
        }
        $progress->finish();
        $this->newLine();

        DB::transaction(function () use ($tables, $metadata, $sizes, $owners) {
            $users = $songs = $albums = $artists = $files = [];
            $connection = DB::connection();
            $migrationTable = config('database.migrations.table', 'migrations');
            $names = array_filter($connection->getSchemaBuilder()->getTableListing('public'), fn($name) => $name !== 'public.' . $migrationTable);
            $connection->statement('TRUNCATE TABLE ' . implode(', ', array_map($connection->getQueryGrammar()->wrapTable(...), $names)) . ' RESTART IDENTITY');
            foreach ($tables['users'] as $row) {
                $users[$row['id']] = Models\User::create(['emos_id' => $row['emos_id'], 'username' => $row['username'], 'role' => 'user', 'size_upload' => $sizes[$row['id']] ?? 0, 'created_at' => $row['first_uploaded_at'], 'updated_at' => $row['first_uploaded_at']]);
            }
            $firstOwner = collect($owners)->sortBy('created_at')->first();
            $library = Models\MediaLibrary::ensureDefault($users[$firstOwner['user_id']]);
            $personsBySong = collect($tables['persons'])->groupBy('todb_music_song_id');
            $personsByAlbum = collect($tables['persons'])->groupBy('todb_music_album_id');
            $todbSongs = [];
            foreach ($tables['songs'] as $row) {
                $owner = $owners[$row['id']];
                $createdAt = $row['created_at'] ?: $owner['created_at'];
                $recording = Models\Recording::create(['name' => $row['name'], 'user_id' => $users[$owner['user_id']]->id, 'created_at' => $createdAt, 'updated_at' => $row['updated_at'] ?: $createdAt]);
                $song = Models\Song::create([...$this->catalog($row, $users[$owner['user_id']]->id, $createdAt), 'todb_id' => $row['todb_music_song_id'], 'source_data' => ['emos_api' => $row, 'persons' => $row['todb_music_song_id'] ? $personsBySong->get($row['todb_music_song_id'], collect())->all() : []], 'recording_id' => $recording->id, 'library_id' => $library->id, 'release_date' => $this->releaseDate($row['release_date'])]);
                $songs[$row['id']] = $song;
                if ($row['todb_music_song_id']) {
                    $todbSongs[$row['todb_music_song_id']] = $song;
                }
            }
            $tracksByAlbum = collect($tables['tracks'])->groupBy('todb_music_album_id');
            foreach ($tables['albums'] as $row) {
                $tracks = $tracksByAlbum->get($row['todb_music_album_id'], collect());
                $owner = $todbSongs[$tracks->first()['todb_music_song_id']];
                $date = $this->releaseDate($row['release_date']);
                $albums[$row['todb_music_album_id']] = Models\Album::create([...$this->catalog($row, $owner->user_id, $owner->created_at), 'todb_id' => $row['todb_music_album_id'], 'source_data' => ['emos_api' => $row, 'tracks' => $tracks->all(), 'persons' => $personsByAlbum->get($row['todb_music_album_id'], collect())->all()], 'release_date' => $date, 'release_year' => $date ? (int)substr($date, 0, 4) : null, 'label' => $row['release_company'] ?? '', 'musicbrainz_id' => Str::isUuid($row['music_brainz_id'] ?? '') ? $row['music_brainz_id'] : null]);
            }
            $positions = [];
            foreach ($tables['tracks'] as $row) {
                $album = $albums[$row['todb_music_album_id']];
                $song = $todbSongs[$row['todb_music_song_id']];
                $disc = max(1, (int)$row['disc_number']);
                $track = max(1, (int)$row['track_number']);
                while (isset($positions[$album->id][$disc][$track])) {
                    $track++;
                }
                $positions[$album->id][$disc][$track] = true;
                Models\AlbumTrack::create(['album_id' => $album->id, 'song_id' => $song->id, 'disc_number' => $disc, 'track_number' => $track, 'created_at' => $song->created_at, 'updated_at' => $song->updated_at]);
            }
            $links = [];
            foreach ($tables['persons'] as $row) {
                if ($row['role'] !== 'artist') {
                    continue;
                }
                $entity = $row['todb_music_song_id'] ? ($todbSongs[$row['todb_music_song_id']] ?? null) : ($albums[$row['todb_music_album_id']] ?? null);
                if (!$entity) {
                    continue;
                }
                $key = $row['person_id'] ? 'person:' . $row['person_id'] : ($row['ext_id'] ? 'ext:' . $row['ext_id'] : 'name:' . mb_strtolower($row['name']));
                $artist = $artists[$key] ??= Models\Artist::create(['name' => $row['name'], 'user_id' => $entity->user_id, 'todb_id' => $row['person_id'] ?: null, 'image_url' => $this->image($row['image_profile']), 'source_data' => ['emos_api' => $row], 'created_at' => $entity->created_at, 'updated_at' => $row['updated_at'] ?: $entity->updated_at]);
                if (!isset($links[$entity->id][$artist->id])) {
                    $entity->artists()->attach($artist->id);
                    $links[$entity->id][$artist->id] = true;
                }
            }
            $covers = [];
            foreach ($tables['media'] as $row) {
                $song = $songs[$row['music_song_id']];
                $userId = $users[$row['user_id']]->id;
                $meta = $metadata[$row['id']]['data'];
                $format = $meta['format'];
                if (empty($links[$song->id])) {
                    foreach (array_unique((array)($meta['artists'] ?? $meta['artist'] ?? [])) as $name) {
                        if (!is_string($name) || trim($name) === '') {
                            continue;
                        }
                        $artist = Models\Artist::select(['id'])->firstOrCreate(['name' => Str::limit(trim($name), 255, '')], ['user_id' => $song->user_id, 'created_at' => $song->created_at, 'updated_at' => $song->updated_at]);
                        if (!isset($links[$song->id][$artist->id])) {
                            $song->artists()->attach($artist->id);
                            $links[$song->id][$artist->id] = true;
                        }
                    }
                }
                if ($cover = $metadata[$row['id']]['cover']) {
                    $image = $covers[$cover['sha256']] ??= Models\StoredFile::create(['kind' => 'cover', 'user_id' => $userId, 'file_name' => $cover['name'], 'file_size' => $cover['size'], 'file_path' => $cover['path'], 'mime_type' => $cover['mime'], 'sha256' => $cover['sha256'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]);
                    $meta['cover_file_id'] = $image->id;
                    if (!$song->cover_id) {
                        $song->cover_id = $image->id;
                        Models\Song::withoutTimestamps(fn() => $song->save());
                    }
                }
                $files[$row['id']] = Models\StoredFile::create(['kind' => 'audio', 'song_id' => $song->id, 'user_id' => $userId, 'file_name' => $song->name . '.' . $row['file_suffix'], 'file_size' => $row['file_size'], 'file_path' => $this->filePath($row['path_url']), 'mime_type' => $row['file_type'], 'sha256' => strtolower($row['file_hash']), 'quality' => Services\Quality::classify($format), 'codec' => strtolower($format['codec'] ?? ''), 'bitrate' => (int)round(($format['bitrate'] ?? 0) / 1000), 'sample_rate' => (int)($format['sampleRate'] ?? 0), 'bit_depth' => (int)($format['bitsPerSample'] ?? 0), 'channels' => (int)($format['numberOfChannels'] ?? 0), 'duration' => (float)$format['duration'], 'metadata' => [...$meta, 'emos_api' => $row], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]);
            }
            $primary = collect($tables['media'])->where('is_primary', true)->groupBy('music_song_id');
            foreach ($songs as $oldId => $song) {
                if ($row = $primary->get($oldId)?->first()) {
                    $files[$row['id']]->update(['is_default' => true]);
                } else {
                    Services\Quality::ensureDefault($song);
                }
            }
            $defaultLyrics = [];
            foreach ($tables['lyrics'] as $row) {
                $song = $songs[$row['music_song_id']];
                if (trim($row['content']) === '') {
                    continue;
                }
                Models\SongLyric::create([...$this->lyric($row), 'song_id' => $song->id, 'user_id' => isset($users[$row['user_id']]) ? $users[$row['user_id']]->id : $song->user_id, 'source_file_id' => $files[$row['music_media_id']]->id ?? null, 'is_default' => !isset($defaultLyrics[$song->id]), 'sort_order' => $defaultLyrics[$song->id] ?? 0, 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]);
                $defaultLyrics[$song->id] = ($defaultLyrics[$song->id] ?? 0) + 1;
            }
            foreach ($tables['favorites'] as $row) {
                $songs[$row['relation_id']]->fans()->attach($users[$row['user_id']]->id, ['created_at' => $row['updated_at'], 'updated_at' => $row['updated_at']]);
            }
            Models\SyncState::create(['id' => 'library', 'revision' => 1]);
        });
        // The existing scheduled SyncLibrary job picks up this revision without a queue dependency here.
    }

    private function filePath(string $path): string
    {
        foreach (['/files/' => 'upload/', '/store/' => 'down/'] as $prefix => $replacement) {
            if (str_starts_with($path, $prefix)) {
                $suffix = substr($path, strlen($prefix));
                if ($suffix !== '' && !str_contains($suffix, '..') && !preg_match('~[\\\\\x00-\x1f?#]|//~', $suffix)) {
                    return $replacement . $suffix;
                }
            }
        }

        throw new \RuntimeException('无法转换资源路径：' . $path);
    }

    private function catalog(array $row, int $userId, mixed $createdAt): array
    {
        return ['name' => $row['name'], 'description' => $row['description'] ?? '', 'image_url' => $this->image($row['image_poster']), 'user_id' => $userId, 'created_at' => $createdAt, 'updated_at' => $row['updated_at'] ?: $createdAt, 'deleted_at' => $row['deleted_at']];
    }

    private function image(?string $path): ?string
    {
        return $path ? (str_starts_with($path, 'http') ? $path : 'https://image.theotherdb.org/original/' . ltrim($path, '/')) : null;
    }

    private function releaseDate(?string $date): ?string
    {
        return $date && $date !== '0000-00-00' ? $date : null;
    }

    private function lyric(array $row): array
    {
        $content = $row['content'];
        if ($row['type'] === 'inline') {
            $content = implode("\n", array_map(function ($line) {
                $time = max(0, (int)($line['timestamp'] ?? 0));

                return sprintf('[%02d:%02d.%03d]', intdiv($time, 60000), intdiv($time, 1000) % 60, $time % 1000) . $line['text'];
            }, json_decode($content, true, 512, JSON_THROW_ON_ERROR)));
        }
        $lines = [];
        foreach (preg_split('/\r?\n/', $content) as $line) {
            if (preg_match('/^\[(\d+):(\d{2})(?:[.:](\d+))?\](.*)$/u', $line, $match)) {
                $start = ((int)$match[1] * 60 + (int)$match[2]) * 1000 + (int)substr(str_pad($match[3] ?? '', 3, '0'), 0, 3);
                $lines[] = ['start' => $start, 'value' => preg_replace('/[\[<]\d+:\d{2}(?:[.:]\d+)?[\]>]/u', '', $match[4])];
            }
        }
        $synced = $lines !== [];
        $name = Str::limit($row['name'] ?: '迁移歌词 #' . $row['id'], 100, '');
        $language = $row['language'] && $row['language'] !== 'unknown' ? $row['language'] : 'und';

        return ['name' => $name, 'format' => $synced ? 'lrc' : 'text', 'kind' => 'main', 'language' => $language, 'source' => 'emos_api', 'source_format' => $row['type'], 'source_data' => ['emos_api' => $row], 'content' => $content, 'lrc' => $content, 'structured' => [['displayTitle' => $name, 'lang' => $language, 'kind' => 'main', 'synced' => $synced, 'line' => $synced ? $lines : array_map(fn($line) => ['value' => $line], preg_split('/\r?\n/', $content))]]];
    }
}
