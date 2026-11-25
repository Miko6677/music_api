<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MusicBrainzController extends Controller
{
    public function releases(Request $request)
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:150'],
            'artist' => ['nullable', 'string', 'max:150'],
        ]);
        $title = trim($data['q']);
        $artist = trim($data['artist'] ?? '');
        abort_if($title === '', 422, '请输入专辑名称。');

        $quote = static fn (string $value): string => '"' . addcslashes($value, "\\\"") . '"';
        $query = 'release:' . $quote($title);
        if ($artist !== '') {
            $query .= ' AND artist:' . $quote($artist);
        }
        $cacheKey = 'musicbrainz:releases:' . hash('sha256', mb_strtolower($query));

        return Cache::remember($cacheKey, now()->addHour(), function () use ($query) {
            $response = Http::acceptJson()
                ->withHeaders(['User-Agent' => 'EmoS Music/1.0 (https://github.com/somebyteorg/music_api)'])
                ->connectTimeout(4)
                ->timeout(12)
                ->get('https://musicbrainz.org/ws/2/release', [
                    'query' => $query,
                    'fmt' => 'json',
                    'limit' => 10,
                    'inc' => 'artist-credits+labels+media',
                ]);
            $response->throw();

            $items = collect($response->json('releases', []))
                ->filter(fn ($release) => is_array($release) && Str::isUuid($release['id'] ?? ''))
                ->map(function (array $release) {
                    $media = collect($release['media'] ?? []);
                    $labelInfo = collect($release['label-info'] ?? []);
                    $group = $release['release-group'] ?? [];
                    $labels = $labelInfo
                        ->map(fn ($info) => $info['label']['name'] ?? null)
                        ->filter()
                        ->unique()
                        ->values();

                    return [
                        'id' => $release['id'],
                        'name' => $release['title'] ?? '',
                        'release_date' => $release['date'] ?? null,
                        'release_type' => $group['primary-type'] ?? null,
                        'status' => $release['status'] ?? null,
                        'country' => $release['country'] ?? null,
                        'format' => $media->pluck('format')->filter()->unique()->implode(' / ') ?: null,
                        'artists' => collect($release['artist-credit'] ?? [])
                            ->filter(fn ($credit) => is_array($credit) && isset($credit['name']))
                            ->map(fn ($credit) => $credit['name'])
                            ->values(),
                        'label' => $labels->implode(' / ') ?: null,
                        'catalog_number' => $labelInfo->pluck('catalog-number')->filter()->unique()->implode(' / ') ?: null,
                        'total_discs' => $media->isEmpty() ? null : $media->count(),
                        'total_tracks' => $media->sum(fn ($medium) => (int) ($medium['track-count'] ?? 0)) ?: null,
                        'source_url' => 'https://musicbrainz.org/release/' . $release['id'],
                        'cover_url' => 'https://coverartarchive.org/release/' . $release['id'] . '/front',
                    ];
                })
                ->values();

            return ['items' => $items];
        });
    }
}
