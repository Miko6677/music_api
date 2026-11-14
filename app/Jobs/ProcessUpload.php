<?php

namespace App\Jobs;

use App\Models;
use App\Services;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ProcessUpload implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    public function __construct(public string $sessionId)
    {
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('upload-' . $this->sessionId))->releaseAfter(5)->expireAfter(300)];
    }

    public function handle(Services\AudioMetadata $parser): void
    {
        $session = Models\UploadSession::select(Models\UploadSession::FIELDS)->findOrFail($this->sessionId);
        if ($session->status === 'complete' || $session->status === 'cancelled') {
            return;
        }
        if (!$session->tus_id) {
            throw new \RuntimeException('上传文件标识缺失。');
        }
        $path = realpath(rtrim(config('services.tusd.path'), '/') . '/' . $session->tus_id);
        $root = realpath(config('services.tusd.path'));
        if (!$path || !$root || !str_starts_with($path, $root . '/') || filesize($path) !== $session->file_size) {
            throw new \RuntimeException('上传文件尚未完整写入。');
        }
        $sha = hash_file('sha256', $path);
        if ($session->sha256 && !hash_equals($session->sha256, $sha)) {
            throw new \RuntimeException('文件内容与上传前校验不一致，请重新上传这份文件。');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $meta = null;
        if ($session->kind === 'cover') {
            $image = @getimagesize($path);
            if (!$image || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $image[0] > 16000 || $image[1] > 16000) {
                throw new \RuntimeException('请上传有效的 JPG、PNG 或 WebP 封面。');
            }
        } else {
            $meta = $parser->parse($path);
        }
        // Persist artwork immediately: metadata may delete temporary covers after 120 seconds.
        $cover = $session->kind === 'audio' ? $parser->cover($meta) : null;
        if ($session->song_id) {
            Models\UploadSession::whereKey($session->id)->whereNotIn('status', ['complete', 'cancelled'])->update(['status' => 'publishing']);
        }
        Cache::lock('upload-hash-' . $sha, 300)->block(10, function () use ($session, $path, $mime, $meta, $sha, $cover) {
            DB::transaction(function () use ($session, $path, $mime, $meta, $sha, $cover) {
                $session = Models\UploadSession::select(Models\UploadSession::FIELDS)->lockForUpdate()->findOrFail($session->id);
                if ($session->status === 'complete' || $session->status === 'cancelled') {
                    return;
                }
                // Deduplicate this user's pending or published file, preserving other uploaders' ownership.
                $existing = Models\StoredFile::select(['id', 'song_id', 'upload_context', 'metadata'])->where('sha256', $sha)->where('user_id', $session->user_id)->lockForUpdate()->first();
                if ($existing) {
                    $existing->rememberUploadContext($session->context);
                    if ($session->prepared_lyrics) {
                        $existing->metadata = [...($existing->metadata ?? []), 'prepared_lyrics' => $session->prepared_lyrics];
                        $existing->save();
                    }
                    $this->publishToSong($session, $existing);
                    $session->update(['status' => 'complete', 'file_id' => $existing->id]);

                    return;
                }
                if ($cover) {
                    $image = Models\StoredFile::select(['id'])->where('kind', 'cover')->where('sha256', $cover['sha256'])->first();
                    if (!$image) {
                        $image = Models\StoredFile::create(['user_id' => $session->user_id, 'kind' => 'cover', 'file_name' => $cover['name'], 'file_size' => $cover['size'], 'file_path' => $cover['path'], 'mime_type' => $cover['mime'], 'sha256' => $cover['sha256']]);
                    }
                    $meta['cover_file_id'] = $image->id;
                }
                $format = $meta['format'] ?? [];
                $meta['prepared_lyrics'] = $session->prepared_lyrics ?? [];
                $file = Models\StoredFile::create([
                    'user_id'   => $session->user_id, 'kind' => $session->kind, 'file_name' => $session->file_name,
                    'file_size' => $session->file_size, 'file_path' => Services\StoragePath::relative($path), 'mime_type' => $mime, 'sha256' => $sha,
                    'metadata'  => $meta, 'upload_context' => $session->context ? [$session->context] : [],
                    'quality'   => Services\Quality::classify($format), 'codec' => strtolower($format['codec'] ?? ''),
                    'bitrate'   => (int)round(($format['bitrate'] ?? 0) / 1000), 'sample_rate' => (int)($format['sampleRate'] ?? 0),
                    'bit_depth' => (int)($format['bitsPerSample'] ?? 0), 'channels' => (int)($format['numberOfChannels'] ?? 0),
                    'duration'  => (float)($format['duration'] ?? 0),
                ]);
                Models\User::whereKey($session->user_id)->increment('size_upload', $session->file_size);
                $this->publishToSong($session, $file);
                $session->update(['status' => 'complete', 'file_id' => $file->id]);
            });
        });
    }

    private function publishToSong(Models\UploadSession $session, Models\StoredFile $file): void
    {
        if (!$session->song_id || $file->song_id === $session->song_id) {
            return;
        }
        abort_if($file->song_id !== null, 409, '文件已关联其他歌曲，请到原歌曲页面移动音频。');
        $user = Models\User::select(Models\User::PUBLIC_FIELDS)->findOrFail($session->user_id);
        abort_unless(Models\Song::whereKey($session->song_id)->exists(), 409, '目标歌曲已被删除，请先恢复歌曲再重试。');
        app(Services\FilePublisher::class)->publish($user, $file->id, ['song_id' => $session->song_id]);
    }

    public function failed(?\Throwable $e): void
    {
        $message = match (true) {
            $e instanceof ValidationException                                     => collect($e->errors())->flatten()->first(),
            $e instanceof \PDOException                                           => '保存文件资料失败，请稍后重试。',
            $e instanceof \RuntimeException, $e instanceof HttpExceptionInterface => $e->getMessage(),
            default                                                               => '文件处理失败，请稍后重试。',
        };
        Models\UploadSession::whereKey($this->sessionId)->whereNotIn('status', ['complete', 'cancelled'])->update(['status' => 'failed', 'error' => $message]);
    }
}
