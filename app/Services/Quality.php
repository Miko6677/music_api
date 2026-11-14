<?php

namespace App\Services;

use App\Models;

class Quality
{
    public static function classify(array $format): string
    {
        $codec = strtolower($format['codec'] ?? '');
        $lossless = ($format['lossless'] ?? false) || in_array($codec, ['flac', 'alac', 'wav', 'pcm', 'aiff', 'ape', 'wavpack']);
        if ($lossless) {
            return ($format['bitsPerSample'] ?? 0) > 16 || ($format['sampleRate'] ?? 0) > 48000 ? 'hires' : 'lossless';
        }
        $threshold = str_contains($codec, 'opus') ? 128000 : (str_contains($codec, 'aac') ? 192000 : 256000);

        return ($format['bitrate'] ?? 0) >= $threshold ? 'high' : 'standard';
    }

    public static function ensureDefault(Models\Song $song): void
    {
        $files = $song->files()->select(['id', 'quality', 'is_default'])->orderBy('created_at')->orderBy('id')->get();
        if ($files->isEmpty() || $files->contains('is_default', true)) {
            return;
        }
        $selected = $files->firstWhere('quality', 'high') ?? $files->firstWhere('quality', 'lossless') ?? $files->firstWhere('quality', 'standard') ?? $files->first();
        $selected->update(['is_default' => true]);
    }

    public static function select(Models\Song $song, string $preference): ?Models\StoredFile
    {
        $files = $song->files()->select([...Models\StoredFile::FIELDS, 'file_path'])->orderByDesc('is_default')->orderBy('created_at')->orderBy('id')->get();

        return $files->firstWhere('quality', $preference) ?? $files->firstWhere('is_default', true) ?? $files->first();
    }
}
