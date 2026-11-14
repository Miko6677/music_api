<?php

namespace App\Http\Controllers;

use App\Models;
use App\Services;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FileController extends Controller
{
    public function index(Request $r)
    {
        $r->validate(['page' => 'nullable|integer|min:1', 'kind' => 'nullable|in:audio,cover', 'pending' => 'nullable|boolean', 'active' => 'nullable|boolean', 'q' => 'nullable|string|max:255', 'batch_id' => 'nullable|uuid']);
        $fields = $r->boolean('pending') ? Models\StoredFile::UPLOAD_FIELDS : [...Models\StoredFile::FIELDS, 'upload_context'];
        $q = Models\StoredFile::select($fields)->withTrashed()->where('user_id', $r->user()->id)->with('song:id,name');
        if ($r->filled('kind')) {
            $q->where('kind', $r->input('kind'));
        }
        if ($r->boolean('active')) {
            $q->whereNull('deleted_at');
        }
        if ($r->filled('q')) {
            $q->where('file_name', 'ilike', '%' . addcslashes($r->input('q'), '%_\\') . '%');
        }
        if ($r->filled('batch_id')) {
            $q->whereJsonContains('upload_context', [['batch_id' => $r->input('batch_id')]]);
        }
        if ($r->boolean('pending')) {
            $q->whereNull('song_id')->whereNull('deleted_at')->where('kind', 'audio');
        }

        $page = $q->orderByDesc('created_at')->orderBy('id')->paginate($this->pageSize($r));

        return $this->FormatPage($page, $r->boolean('pending') ? app(Services\UploadPreview::class)->many($page->getCollection()) : $page->getCollection()->map(fn($file) => [...$file->toArray(), 'upload_context' => $file->upload_context]));
    }

    public function image(Request $r, string $id, Services\ImageAssets $images)
    {
        $file = Models\StoredFile::select(Models\StoredFile::FIELDS)->where('kind', 'cover')->where('user_id', $r->user()->id)->findOrFail($id);

        return ['file' => $file, 'references' => $images->references($id, $r->user())];
    }

    public function drafts(Request $r)
    {
        $data = $r->validate([
            'items'                                 => ['required', 'array', 'min:1', 'max:100'],
            'items.*.file_id'                       => ['required', 'uuid', 'distinct'],
            'items.*.data'                          => ['required', 'array'],
            ...app(Services\FilePublisher::class)->rules('items.*.data.'),
            'items.*.organize'                      => ['required', 'array:coverChoice,includeArtwork,excludedPictureIds,replaceCover'],
            'items.*.organize.coverChoice'          => ['present', 'nullable', 'string', 'max:40'],
            'items.*.organize.includeArtwork'       => ['required', 'boolean'],
            'items.*.organize.replaceCover'         => ['required', 'boolean'],
            'items.*.organize.excludedPictureIds'   => ['present', 'array', 'max:100'],
            'items.*.organize.excludedPictureIds.*' => ['uuid', 'distinct'],
        ]);
        DB::transaction(function () use ($data, $r) {
            $files = Models\StoredFile::select(['id', 'user_id', 'song_id', 'kind', 'publication'])->where('user_id', $r->user()->id)
                ->where('kind', 'audio')->whereIn('id', array_column($data['items'], 'file_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($files->count() === count($data['items']), 409, '部分音频已入库或已删除，请刷新后重新整理。');
            foreach ($data['items'] as $item) {
                $file = $files[$item['file_id']];
                if ($file->song_id) {
                    abort_unless(app(Services\PublicationReceipts::class)->replay($r->user(), $file, $item['data']), 409, '部分音频已入库，请刷新后重新整理。');
                    continue;
                }
                $item['organize']['coverChoice'] ??= '';
                $files[$item['file_id']]->update(['upload_draft' => ['data' => $item['data'], 'organize' => $item['organize']]]);
            }
        });

        return ['message' => '整理草稿已保存。'];
    }

    public function update(Request $r, string $id)
    {
        $data = $r->validate(['song_id' => ['required', 'uuid', Rule::exists('songs', 'id')->whereNull('deleted_at')], 'is_default' => 'sometimes|boolean', 'updated_at' => 'required|date']);

        return DB::transaction(function () use ($r, $id, $data) {
            $file = Models\StoredFile::select(Models\StoredFile::FIELDS)->lockForUpdate()->findOrFail($id);
            abort_unless($file->song_id && $file->kind === 'audio', 422, '请先确认这份音频所属的歌曲。');
            abort_unless($file->updated_at->equalTo($data['updated_at']), 409, '资源已更新，请刷新后重试。');
            $song = Models\Song::select(Models\Song::FIELDS)->lockForUpdate()->findOrFail($data['song_id']);
            $oldId = $file->song_id;
            $before = app(Services\Activity::class)->snapshot($file);
            $before['_defaults'][$song->id] = $song->files()->where('is_default', true)->value('id');
            if ($song->id !== $oldId) {
                abort_if(Models\StoredFile::where('song_id', $song->id)->where('sha256', $file->getRawOriginal('sha256'))->exists(), 409, '目标歌曲已有相同文件。');
                $file->is_default = false;
            }
            if ($data['is_default'] ?? false) {
                $song->files()->update(['is_default' => false]);
                $file->is_default = true;
            }
            $file->song_id = $song->id;
            $file->save();
            Services\Quality::ensureDefault($song);
            if ($oldId !== $song->id && ($old = Models\Song::select(['id'])->find($oldId))) {
                Services\Quality::ensureDefault($old);
            }
            $file = Models\StoredFile::select(Models\StoredFile::FIELDS)->findOrFail($file->id);
            app(Services\Activity::class)->record($r->user(), 'updated', 'files', $file, $before);
            app(Services\SyncScheduler::class)->touch();

            return ['file' => $file];
        });
    }

    public function preview(Request $r, string $user, string $id)
    {
        abort_unless((string)$r->user()->id === $user, 403);
        $file = Models\StoredFile::select(['id', 'file_path', 'mime_type'])->findOrFail($id);

        return app(Services\FileDelivery::class)->response($file->file_path, $file->mime_type);
    }

    public function publish(Request $r, string $id)
    {
        $data = $r->validate(app(Services\FilePublisher::class)->rules());

        return app(Services\FilePublisher::class)->publish($r->user(), $id, $data);
    }

    public function publishBatch(Request $r)
    {
        $data = $r->validate([
            'items'           => ['required', 'array', 'min:1', 'max:100'],
            'items.*.file_id' => ['required', 'uuid', 'distinct'],
            ...app(Services\FilePublisher::class)->rules('items.*.'),
        ]);

        return app(Services\FilePublisher::class)->publishMany($r->user(), $data['items']);
    }

    public function previews(Request $r)
    {
        $data = $r->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['required', 'uuid', 'distinct']]);
        $files = Models\StoredFile::select(Models\StoredFile::UPLOAD_FIELDS)->where('user_id', $r->user()->id)->whereIn('id', $data['ids'])->with('song:id,name,recording_version')->get();
        abort_unless($files->count() === count($data['ids']), 404, '部分文件不存在或无法访问。');

        return ['files' => app(Services\UploadPreview::class)->many($files)];
    }

    public function destroy(Request $r, string $id)
    {
        $data = $r->validate(['reason' => 'nullable|string|max:1000', 'updated_at' => 'required|date']);

        return DB::transaction(function () use ($r, $id, $data) {
            $file = Models\StoredFile::select(Models\StoredFile::FIELDS)->lockForUpdate()->findOrFail($id);
            abort_unless($file->user_id === $r->user()->id || $r->user()->canModerate(), 403, '只有上传者、编辑或管理员可以删除文件。');
            abort_if($file->user_id !== $r->user()->id && blank($data['reason'] ?? ''), 422, '删除他人的资源时，请填写原因。');
            abort_unless($file->updated_at->equalTo($data['updated_at']), 409, '资源已更新，请刷新后重试。');
            if ($file->kind === 'cover') {
                $references = app(Services\ImageAssets::class)->references($file->id, $r->user());
                abort_if($references, 409, '图片仍被使用：' . implode('、', $references) . '。请先从这些位置移除图片，再删除。');
            }
            $before = app(Services\Activity::class)->snapshot($file);
            $file->delete();
            if ($file->song_id && ($song = Models\Song::select(['id'])->find($file->song_id))) {
                Services\Quality::ensureDefault($song);
            }
            app(Services\Activity::class)->record($r->user(), 'deleted', 'files', $file, $before, $data['reason'] ?? null);
            if ($file->user_id !== $r->user()->id) {
                Models\InboxMessage::create(['user_id' => $file->user_id, 'title' => '你上传的资源已被删除', 'body' => $file->file_name . '：' . $data['reason'], 'link' => '/history']);
            }
            app(Services\SyncScheduler::class)->touch();

            return ['message' => '资源已删除，原文件保留，可从操作记录中恢复。'];
        });
    }
}
