<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Collection;

class UploadPreview
{
    public function one(Models\StoredFile $file): array
    {
        return $this->many(collect([$file]))[0];
    }

    public function many(Collection $files): array
    {
        $titles = $files->where('kind', 'audio')->map(fn($file) => trim((string)($file->metadata['title'] ?? '')))->filter()->unique()->values();
        $matches = $titles->isEmpty() ? collect() : Models\Song::select(['id', 'name', 'recording_id', 'recording_version', 'library_id'])
            ->whereIn('name', $titles)->with(['artists:id,name', 'albums:id,name', 'library:id,name'])
            ->withAvg('files as duration', 'duration')->orderByDesc('updated_at')->limit(max(20, $titles->count() * 5))->get()->groupBy('name');

        return $files->map(function (Models\StoredFile $file) use ($matches) {
            $metadata = $file->metadata ?? [];
            $warnings = [];
            if ($file->kind === 'audio') {
                if (blank($metadata['title'] ?? null)) {
                    $warnings[] = '未识别歌曲名，请核对文件名。';
                }
                if (blank($metadata['artist'] ?? null) && empty($metadata['artists'])) {
                    $warnings[] = '未识别音乐人，请补充资料。';
                }
                if (preg_match('/现场|翻唱|伴奏|重制|\b(?:live|remix|instrumental|remaster)\b/iu', $file->file_name . ' ' . ($metadata['title'] ?? ''))) {
                    $warnings[] = '包含录音版本信息，请核对现场、混音或重制版本。';
                }
            }
            $candidates = ($matches->get(trim($metadata['title'] ?? ''), collect()))->where('id', '!=', $file->song_id)->take(5)->map(function (Models\Song $song) use ($file) {
                $difference = $song->duration ? abs($file->duration - (float)$song->duration) : null;

                return [
                    'id'                  => $song->id, 'name' => $song->name, 'recording_id' => $song->recording_id, 'recording_version' => $song->recording_version,
                    'artists'             => $song->artists->toArray(), 'albums' => $song->albums->toArray(), 'library' => $song->library?->toArray(),
                    'duration'            => $song->duration ? (float)$song->duration : null,
                    'duration_difference' => $difference,
                    'different_recording' => $difference !== null && $difference > max(5, $file->duration * .02),
                ];
            })->values()->all();
            if ($candidates) {
                $warnings[] = '已有同名歌曲，请先核对音乐人、专辑和录音版本。';
            }
            $lyrics = array_map(fn($lyric) => array_intersect_key($lyric, array_flip(['name', 'language', 'format', 'source_format'])), $metadata['prepared_lyrics'] ?? []);

            return [...$file->toArray(), 'upload_context' => $file->upload_context, 'upload_draft' => $file->upload_draft, 'preview' => [
                'cover_id' => $metadata['cover_file_id'] ?? null, 'lyrics' => $lyrics,
                'warnings' => $warnings, 'matches' => $candidates,
            ]];
        })->values()->all();
    }
}
