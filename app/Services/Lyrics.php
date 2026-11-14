<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Lyrics
{
    const FORMATS = ['text', 'lrc', 'elrc', 'ttml'];

    const KINDS = ['main', 'translation', 'pronunciation'];

    public function merge(array $target, array $source): array
    {
        foreach ($source as $version) {
            $duplicate = collect($target)->contains(fn($entry) => $entry['content'] === $version['content'] && $entry['language'] === $version['language'] && $entry['kind'] === $version['kind'] && $entry['format'] === $version['format']);
            if (!$duplicate) {
                $target[] = [...$version, 'is_default' => !$target && $version['is_default']];
            }
        }

        return $this->defaults($target);
    }

    public function defaults(array $versions, ?string $preferred = null): array
    {
        if (!$versions) {
            return [];
        }
        $preferred ??= collect($versions)->firstWhere('is_default', true)['id'] ?? $versions[0]['id'];

        return array_map(fn($version) => [...$version, 'is_default' => $version['id'] === $preferred], array_values($versions));
    }

    public function import(Models\StoredFile $file): array
    {
        $versions = [];
        foreach ($file->metadata['prepared_lyrics'] ?? [] as $lyric) {
            $versions[] = [...$this->entry($lyric, $file->user_id),
                'source_file_id' => $file->id,
                'source_format'  => $lyric['source_format'] ?? $lyric['format'],
                'source_data'    => $lyric['source_data'] ?? null,
            ];
        }

        return $this->defaults($versions);
    }

    public function entry(array $data, int $userId, ?array $existing = null): array
    {
        $content = $data['content'];

        return [
            'id'             => $existing['id'] ?? (string)Str::uuid(),
            'name'           => trim($data['name']), 'format' => $data['format'], 'kind' => $data['kind'],
            'language'       => $data['language'] ?: 'und', 'source' => trim($data['source'] ?? ''),
            'content'        => $content, 'lrc' => $data['lrc'], 'structured' => $data['structured'], 'is_default' => $data['is_default'] ?? false,
            'user_id'        => $existing['user_id'] ?? $userId,
            'created_at'     => $existing['created_at'] ?? now()->toISOString(), 'updated_at' => now()->toISOString(),
            'source_file_id' => $existing['source_file_id'] ?? null,
            'source_format'  => $existing['source_format'] ?? $data['format'],
            'source_data'    => $existing['source_data'] ?? null,
        ];
    }

    public function versions(Models\Song $song): array
    {
        $song->loadMissing('lyrics');

        return $song->lyrics->map(fn(Models\SongLyric $lyric) => [...$lyric->toArray(), 'source_data' => $lyric->source_data, 'structured' => $lyric->structured])->all();
    }

    public function replace(Models\Song $song, array $versions): void
    {
        DB::transaction(function () use ($song, $versions) {
            Models\Song::withTrashed()->select(['id'])->lockForUpdate()->findOrFail($song->id);
            $versions = $this->defaults(array_values($versions));
            $owners = Models\SongLyric::select(['id', 'song_id'])->whereIn('id', array_column($versions, 'id'))->pluck('song_id', 'id');
            $song->lyrics()->toBase()->update(['is_default' => false]);
            $current = $song->lyrics()->lockForUpdate()->get()->keyBy('id');
            $ids = [];
            $default = null;
            foreach ($versions as $position => $entry) {
                $id = $entry['id'] ?? (string)Str::uuid();
                if (($owners->has($id) && $owners->get($id) !== $song->id) || in_array($id, $ids, true)) {
                    $id = (string)Str::uuid();
                }
                if ($entry['is_default'] && $default === null) {
                    $default = $id;
                }
                $lyric = $current->get($id) ?? new Models\SongLyric;
                $lyric->fill(array_intersect_key($entry, array_flip(Models\SongLyric::FIELDS)));
                $lyric->fill(['id' => $id, 'song_id' => $song->id, 'is_default' => false, 'sort_order' => $position]);
                $lyric->source_format = $entry['source_format'] ?? $entry['format'];
                $lyric->save();
                $ids[] = $id;
            }
            $song->lyrics()->whereNotIn('id', $ids)->delete();
            if ($default) {
                $song->lyrics()->whereKey($default)->toBase()->update(['is_default' => true]);
            }
            $song->load('lyrics');
        });
    }

    public function playback(Models\Song $song): string
    {
        $song->loadMissing('lyrics');
        $documents = $song->lyrics->firstWhere('is_default', true)?->structured ?? [];

        return json_encode($documents, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
