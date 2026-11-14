<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class Todb
{
    public function search(string $type, string $q): array
    {
        return $this->request(match ($type) {
            'songs'   => '/music/song/search',
            'albums'  => '/music/album/search',
            'artists' => '/person/search',
        }, ['name' => $q, 'page_size' => 15]);
    }

    private function request(string $path, array $query = []): array
    {
        abort_if(blank(config('services.todb.url')), 503, 'todb 还没有配置，仍可直接维护本地资料。');
        try {
            $r = Http::baseUrl(rtrim(config('services.todb.url'), '/'))->withToken(config('services.todb.api_token'))->withHeaders(['Host' => config('services.todb.api_host')])->acceptJson()->timeout(20)->connectTimeout(5)->get($path, $query);
        } catch (ConnectionException $e) {
            abort(502, '暂时连不上 todb，请稍后再试。');
        }
        abort_if($r->status() === 404, 404, 'todb 中没有找到这份资料。');
        abort_unless($r->successful() && is_array($r->json()), 502, 'todb 暂时没有返回有效资料。');

        return $r->json();
    }

    public function info(string $type, string $id): array
    {
        $raw = $this->request(match ($type) {
            'songs'   => "/music/song/$id/info",
            'albums'  => "/music/album/$id/info",
            'artists' => "/person/$id/info",
        });
        $mapped = ['name' => $raw['name'] ?? '', 'description' => $raw['description'] ?? '', 'image_url' => $raw['image_poster'] ?? $raw['image_profile'] ?? null];
        if ($type !== 'artists') {
            $mapped['release_date'] = empty($raw['release_date']) ? null : substr($raw['release_date'], 0, 10);
        }

        return ['fields' => $mapped, 'source' => $raw];
    }
}
