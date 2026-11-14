<?php

namespace App\Http\Controllers;

use App\Models;
use App\Services;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CommunityController extends Controller
{
    public function history(Request $r)
    {
        $data = $r->validate([
            'category'    => ['nullable', Rule::in([Models\ActivityLog::DEFAULT_CATEGORY, 'all', ...array_keys(Models\ActivityLog::CATEGORY_ACTIONS)])],
            'user'        => 'nullable|string|max:100',
            'from'        => 'nullable|date_format:Y-m-d',
            'to'          => 'nullable|date_format:Y-m-d',
            'entity_type' => 'nullable|in:songs,albums,artists,files,playlists,libraries',
            'entity_id'   => 'nullable|string|max:40',
            'mine'        => 'nullable|boolean',
            'page'        => 'nullable|integer|min:1',
        ]);
        abort_if(!empty($data['from']) && !empty($data['to']) && $data['to'] < $data['from'], 422, '结束日期不能早于开始日期。');

        return app(Services\CommunityHistory::class)->page($r, $this->pageSize($r, 16));
    }

    public function restore(Request $r, int $id, Services\HistoryRestore $restore)
    {
        $restore->restore($r->user(), $id);

        return ['message' => '已恢复到修改前的版本。'];
    }

    public function members(Request $r)
    {
        $r->validate(['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $q = Models\User::select([...Models\User::PUBLIC_FIELDS, 'role', 'size_upload', 'created_at']);
        if ($r->filled('q')) {
            $q->named($r->input('q'));
        }

        $page = $q->orderByDesc('size_upload')->orderBy('id')->paginate($this->pageSize($r, 30));

        return $this->FormatPage($page, $page->items());
    }

    public function role(Request $r, int $id)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $data = $r->validate(['role' => 'required|in:user,edit,admin']);

        return DB::transaction(function () use ($r, $id, $data) {
            $admins = Models\User::select(['id'])->where('role', 'admin')->orderBy('id')->lockForUpdate()->get();
            $u = Models\User::select(Models\User::SESSION_FIELDS)->lockForUpdate()->findOrFail($id);
            abort_if($u->role === 'admin' && $data['role'] !== 'admin' && $admins->count() <= 1, 422, '至少需要保留一位管理员。');
            $before = $u->role;
            $u->update($data);
            Models\ActivityLog::create(['user_id' => $r->user()->id, 'action' => 'role.updated', 'entity_type' => 'users', 'entity_id' => (string)$u->id, 'title' => $u->username, 'before' => ['role' => $before], 'after' => $data]);
            Models\InboxMessage::create(['user_id' => $u->id, 'title' => '你的用户角色已更新', 'body' => '当前角色：' . ['user' => '用户', 'edit' => '编辑', 'admin' => '管理员'][$data['role']]]);

            return ['message' => '角色已更新。'];
        });
    }

    public function inbox(Request $r)
    {
        $page = Models\InboxMessage::select(['id', 'title', 'body', 'link', 'read_at', 'created_at'])->where('user_id', $r->user()->id)->orderByDesc('id')->paginate($this->pageSize($r, 30));

        return $this->FormatPage($page, $page->items());
    }

    public function read(Request $r)
    {
        $data = $r->validate(['ids' => 'nullable|array|max:100', 'ids.*' => 'integer']);
        $q = Models\InboxMessage::where('user_id', $r->user()->id)->whereNull('read_at');
        if (isset($data['ids'])) {
            $q->whereIn('id', $data['ids']);
        }
        $q->update(['read_at' => now()]);

        return ['message' => '已读。'];
    }

    public function unread(Request $r)
    {
        return ['count' => Models\InboxMessage::where('user_id', $r->user()->id)->whereNull('read_at')->count()];
    }

    public function sync(Request $r, Services\SyncScheduler $sync)
    {
        abort_unless($r->user()->canModerate(), 403);
        $sync->touch(full: true);

        return ['message' => '已安排重新同步。'];
    }
}
