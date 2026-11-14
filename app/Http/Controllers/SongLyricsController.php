<?php

namespace App\Http\Controllers;

use App\Models;
use App\Services;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SongLyricsController extends Controller
{
    public function save(Request $request, string $id, ?string $lyric = null)
    {
        $data = $request->validate([
            'version'    => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:100'],
            'format'     => ['required', Rule::in(Services\Lyrics::FORMATS)], 'kind' => ['required', Rule::in(Services\Lyrics::KINDS)],
            'language'   => ['required', 'string', 'max:35', 'regex:/^[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{1,8})*$/'],
            'source'     => ['nullable', 'string', 'max:500'], 'content' => ['required', 'string', 'max:100000'],
            'lrc'        => ['present', 'string'], 'structured' => ['required', 'array'],
            'is_default' => ['required', 'boolean'],
        ]);

        return $this->change($request, $id, $data['version'], function (array $versions) use ($request, $data, $lyric) {
            $existing = $lyric ? collect($versions)->firstWhere('id', $lyric) : null;
            abort_if($lyric && !$existing, 404, '歌词版本不存在。');
            $entry = app(Services\Lyrics::class)->entry($data, $request->user()->id, $existing);
            $versions = $lyric ? array_map(fn($version) => $version['id'] === $lyric ? $entry : $version, $versions) : [...$versions, $entry];

            return app(Services\Lyrics::class)->defaults($versions, $entry['is_default'] ? $entry['id'] : null);
        }, $lyric ? '编辑歌词版本' : '添加歌词版本');
    }

    private function change(Request $request, string $id, int $version, callable $change, string $reason): array
    {
        return DB::transaction(function () use ($request, $id, $version, $change, $reason) {
            $song = Models\Song::select(Models\Song::FIELDS)->lockForUpdate()->findOrFail($id);
            abort_if($song->version !== $version, 409, '歌曲资料已有更新，请刷新后再修改歌词。');
            $before = app(Services\Activity::class)->snapshot($song);
            app(Services\Lyrics::class)->replace($song, $change(app(Services\Lyrics::class)->versions($song)));
            $song->version++;
            $song->save();
            app(Services\Activity::class)->record($request->user(), 'updated', 'songs', $song, $before, $reason);
            app(Services\SyncScheduler::class)->touch();

            return ['lyrics' => $song->lyrics->toArray(), 'version' => $song->version];
        });
    }

    public function setDefault(Request $request, string $id, string $lyric)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->change($request, $id, $data['version'], function (array $versions) use ($lyric) {
            abort_unless(collect($versions)->contains('id', $lyric), 404, '歌词版本不存在。');

            return app(Services\Lyrics::class)->defaults($versions, $lyric);
        }, '调整默认歌词版本');
    }

    public function destroy(Request $request, string $id, string $lyric)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:1000']]);

        return $this->change($request, $id, $data['version'], function (array $versions) use ($request, $lyric, $data) {
            $existing = collect($versions)->firstWhere('id', $lyric);
            abort_unless($existing, 404, '歌词版本不存在。');
            abort_unless($existing['user_id'] === $request->user()->id || $request->user()->canModerate(), 403, '只有上传者、编辑或管理员可以删除这个歌词版本。');
            abort_if($existing['user_id'] !== $request->user()->id && blank($data['reason'] ?? ''), 422, '删除他人的歌词时，请填写原因。');

            return app(Services\Lyrics::class)->defaults(array_values(array_filter($versions, fn($version) => $version['id'] !== $lyric)));
        }, $data['reason'] ?? '删除歌词版本');
    }
}
