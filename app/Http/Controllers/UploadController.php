<?php

namespace App\Http\Controllers;

use App\Jobs;
use App\Models;
use App\Services;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class UploadController extends Controller
{
    public function create(Request $r)
    {
        $data = $r->validate([
            'kind'                  => ['required', 'in:audio,cover'], 'file_name' => ['required', 'string', 'max:255'], 'file_size' => ['required', 'integer', 'min:1'],
            'song_id'               => ['nullable', 'uuid', Rule::prohibitedIf($r->input('kind') !== 'audio'), Rule::exists('songs', 'id')->whereNull('deleted_at')],
            'sha256'                => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'prepared_lyrics'       => ['sometimes', 'array'],
            'context'               => ['nullable', 'array:batch_id,relative_path'],
            'context.batch_id'      => ['required_with:context', 'uuid'],
            'context.relative_path' => ['required_with:context', 'string', 'max:2048'],
        ]);
        $ext = strtolower(pathinfo($data['file_name'], PATHINFO_EXTENSION));
        abort_unless(in_array($ext, config('contribution.' . $data['kind'] . '_extensions'), true), 422, '这类文件暂时不能上传。');
        abort_if($data['file_size'] > config('contribution.' . ($data['kind'] === 'audio' ? 'max_file_size' : 'max_cover_size')), 422, '文件大小超过上传上限。');
        $token = Str::random(64);
        $session = Models\UploadSession::create([...$data, 'user_id' => $r->user()->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7)]);

        return ['session' => $session, 'token' => $token, 'endpoint' => config('services.tusd.url'), 'chunk_size' => config('contribution.chunk_size')];
    }

    public function check(Request $r)
    {
        $data = $r->validate([
            'sha256'                => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'kind' => ['required', 'in:audio,cover'], 'file_size' => ['required', 'integer', 'min:1'],
            'prepared_lyrics'       => ['sometimes', 'array'],
            'context'               => ['nullable', 'array:batch_id,relative_path'],
            'context.batch_id'      => ['required_with:context', 'uuid'],
            'context.relative_path' => ['required_with:context', 'string', 'max:2048'],
        ]);
        $file = DB::transaction(function () use ($r, $data) {
            $file = Models\StoredFile::select(Models\StoredFile::UPLOAD_FIELDS)->where('user_id', $r->user()->id)->where('sha256', $data['sha256'])->where('kind', $data['kind'])->where('file_size', $data['file_size'])->with('song:id,name,recording_version')->lockForUpdate()->first();
            $file?->rememberUploadContext($data['context'] ?? null);
            if ($file && !empty($data['prepared_lyrics'])) {
                $file->metadata = [...($file->metadata ?? []), 'prepared_lyrics' => $data['prepared_lyrics']];
                $file->save();
            }

            return $file;
        });

        return ['file' => $file ? app(Services\UploadPreview::class)->one($file) : null];
    }

    public function show(Request $r, string $id)
    {
        $session = Models\UploadSession::select(Models\UploadSession::FIELDS)->where('user_id', $r->user()->id)->findOrFail($id);

        $file = $session->file_id ? Models\StoredFile::select(Models\StoredFile::UPLOAD_FIELDS)->with('song:id,name,recording_version')->find($session->file_id) : null;

        return ['session' => $session, 'file' => $file ? app(Services\UploadPreview::class)->one($file) : null];
    }

    public function retry(Request $r, string $id)
    {
        $s = Models\UploadSession::select(['id', 'status'])->where('user_id', $r->user()->id)->findOrFail($id);
        abort_unless($s->status === 'failed', 422, '只有解析失败的文件可以重试。');
        $s->update(['status' => 'processing', 'error' => null]);
        Jobs\ProcessUpload::dispatch($s->id);

        return ['message' => '已重新安排解析。'];
    }

    public function hook(Request $r)
    {
        $data = $r->validate(['Type' => 'required|in:pre-create,pre-finish,post-finish', 'Event.Upload' => 'required|array']);
        $upload = $data['Event']['Upload'];
        try {
            return DB::transaction(function () use ($data, $upload) {
                $id = $upload['MetaData']['session_id'] ?? null;
                abort_unless(is_string($id) && Str::isUuid($id), 422, '无效的上传授权。');
                $s = Models\UploadSession::select(Models\UploadSession::FIELDS)->lockForUpdate()->findOrFail($id);
                $token = $upload['MetaData']['token'] ?? '';
                abort_unless(is_string($token) && hash_equals($s->token_hash, hash('sha256', $token)), 403, '上传凭证无效。');
                abort_if($s->expires_at->isPast() || $s->status === 'cancelled', 410, '上传凭证过期了，请重新选择文件。');
                abort_unless((int)($upload['Size'] ?? 0) === $s->file_size && empty($upload['SizeIsDeferred']), 422, '文件大小与授权不一致。');
                if ($data['Type'] === 'pre-create') {
                    $tusId = $s->tus_id ?? now()->format('Y/m/d/H') . '/' . $s->id;
                    abort_unless($s->status === 'pending' || ($s->status === 'uploading' && !is_file(rtrim(config('services.tusd.path'), '/') . '/' . $tusId . '.info')), 409, '这份授权已经使用过了。');
                    $s->update(['tus_id' => $tusId, 'status' => 'uploading']);

                    return ['ChangeFileInfo' => ['ID' => $tusId]];
                }
                abort_unless($s->tus_id && ($upload['ID'] ?? '') === $s->tus_id && (int)($upload['Offset'] ?? 0) === $s->file_size, 422, '上传尚未完成。');
                if ($data['Type'] === 'post-finish' && !in_array($s->status, ['complete', 'processing', 'publishing'], true)) {
                    $s->update(['status' => 'processing', 'error' => null]);
                    Jobs\ProcessUpload::dispatch($s->id)->afterCommit()->delay(now()->addSeconds(2));
                }

                return new \stdClass;
            });
        } catch (HttpExceptionInterface $e) {
            return ['RejectUpload' => true, 'HTTPResponse' => ['StatusCode' => $e->getStatusCode(), 'Body' => json_encode(['message' => $e->getMessage()], JSON_UNESCAPED_UNICODE), 'Header' => ['Content-Type' => 'application/json']]];
        }
    }
}
