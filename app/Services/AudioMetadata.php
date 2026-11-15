<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class AudioMetadata
{
    public function parse(string $path): array
    {
        $root = rtrim(config('filesystems.disks.music.root'), '/');
        if (!str_starts_with($path, $root . '/')) {
            throw new \RuntimeException('音频不在存储目录中。');
        }
        $url = rtrim((string)config('services.metadata.global'), '/');
        if ($url === '') {
            throw new \RuntimeException('尚未配置元数据服务 METADATA_URL_GLOBAL。');
        }
        $mapped = rtrim(config('services.metadata.path'), '/') . substr($path, strlen($root));
        try {
            $response = Http::asForm()->timeout(90)->connectTimeout(5)->post($url . '/music/parse', ['url' => $mapped]);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('暂时连不上元数据服务，请稍后再试。', 0, $e);
        }
        if (!$response->successful()) {
            throw new \RuntimeException('元数据服务解析失败，请检查文件或稍后再试。');
        }

        return $this->normalize($response->json());
    }

    public function normalize(mixed $meta): array
    {
        if (!is_array($meta) || empty($meta['format']['hasAudio']) || !is_numeric($meta['format']['duration'] ?? null) || !is_finite((float)$meta['format']['duration']) || $meta['format']['duration'] <= 0 || ($meta['format']['hasVideo'] ?? false)) {
            throw new \RuntimeException('元数据服务未返回有效的纯音频信息。');
        }

        return TextSanitizer::withoutNullBytes($meta);
    }

    public function cover(array $metadata): ?array
    {
        $path = $metadata['cover_path'] ?? null;
        if ($path === null) {
            return null;
        }
        if (!is_string($path)) {
            throw new \RuntimeException('元数据服务返回的封面路径无效。');
        }
        $url = rtrim((string)config('services.metadata.global'), '/');
        try {
            $response = Http::withoutRedirecting()->withOptions(['stream' => true, 'read_timeout' => 30])->timeout(30)->connectTimeout(5)->get($url . $path);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('暂时无法读取元数据服务的封面，请稍后再试。', 0, $e);
        }
        $stream = $response->toPsrResponse()->getBody();
        $image = '';
        try {
            if (!$response->successful()) {
                throw new \RuntimeException('元数据服务的封面下载失败，请稍后再试。');
            }
            while (!$stream->eof()) {
                $chunk = $stream->read(65536);
                if ($chunk === '' && !$stream->eof()) {
                    throw new \RuntimeException('元数据服务的封面未下载完整，请稍后再试。');
                }
                $image .= $chunk;
                if (strlen($image) > config('contribution.max_cover_size')) {
                    throw new \RuntimeException('内嵌封面超出大小限制。');
                }
            }
        } finally {
            $stream->close();
        }
        $size = @getimagesizefromstring($image);
        if (!$size || !in_array($size['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) || $size[0] > 16000 || $size[1] > 16000) {
            throw new \RuntimeException('内嵌封面不是有效的 JPG、PNG 或 WebP 图片，或尺寸超出限制。');
        }
        $ext = match ($size['mime']) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            default      => 'webp'
        };
        $root = rtrim(config('filesystems.disks.music.root'), '/') . '/metadata/' . now()->format('Y/m/d/H');
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new \RuntimeException('无法创建封面存储目录。');
        }
        $sha = hash('sha256', $image);
        $dest = $root . '/' . $sha . '.' . $ext;
        if (!is_file($dest) && file_put_contents($dest, $image, LOCK_EX) !== strlen($image)) {
            throw new \RuntimeException('未能完整保存内嵌封面。');
        }

        return ['path' => StoragePath::relative($dest), 'mime' => $size['mime'], 'size' => strlen($image), 'sha256' => $sha, 'name' => '内嵌封面.' . $ext];
    }
}
