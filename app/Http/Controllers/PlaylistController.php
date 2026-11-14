<?php

namespace App\Http\Controllers;

use App\Models;
use App\Services;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PlaylistController extends Controller
{
    public function __construct(private Services\Playlists $playlists, private Services\PlaylistTracks $tracks)
    {
    }

    public function index(Request $r)
    {
        $r->validate(['page' => 'nullable|integer|min:1', 'q' => 'nullable|string|max:100']);
        $accountId = $this->playlists->account($r->user());
        $query = Models\Server\Playlist::select(Models\Server\Playlist::FIELDS)->visibleTo($accountId);
        if ($r->filled('q')) {
            $query->where('name', 'ilike', '%' . addcslashes($r->input('q'), '%_\\') . '%');
        }
        if ($r->boolean('mine')) {
            $query->participating($accountId);
        }
        $page = $query->orderByDesc('updated_at')->orderBy('id')->paginate($this->pageSize($r));

        return $this->FormatPage($page, $this->playlists->present($page->getCollection(), $accountId)->all());
    }

    public function show(Request $r, string $id)
    {
        $accountId = $this->playlists->account($r->user());
        $item = $this->playlists->item($this->playlists->find($id, $accountId), $accountId);

        return ['item' => $item, 'can_edit' => $item['can_edit_tracks']];
    }

    public function songs(Request $r, string $id)
    {
        $r->validate(['page' => 'nullable|integer|min:1']);
        $playlist = $this->playlists->find($id, $this->playlists->account($r->user()));
        $page = $playlist->entries()->select(Models\Server\PlaylistTrack::FIELDS)->ordered()->paginate($this->pageSize($r, 60));

        return $this->FormatPage($page, $this->tracks->songs($page->getCollection(), $r->user()));
    }

    public function store(Request $r)
    {
        return $this->save($r);
    }

    private function save(Request $r, ?string $id = null)
    {
        $data = $r->validate(['name' => 'required|string|max:150', 'description' => 'nullable|string|max:10000', 'is_public' => 'required|boolean', 'cover_id' => ['nullable', 'uuid', Rule::exists('files', 'id')->where('kind', 'cover')->whereNull('deleted_at')], 'collaborator_ids' => 'present|array|max:30', 'collaborator_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')]]);
        $accountId = $this->playlists->account($r->user());

        return DB::connection('music')->transaction(fn() => ['item' => $this->playlists->item($this->playlists->save($accountId, $data, $id), $accountId)]);
    }

    public function update(Request $r, string $id)
    {
        return $this->save($r, $id);
    }

    public function append(Request $r, string $id)
    {
        $data = $r->validate(['song_ids' => ['required', 'array', 'min:1', 'max:500', Rule::exists('songs', 'id')->whereNull('deleted_at')], 'song_ids.*' => ['required', 'uuid']]);

        return $this->editTracks($r, $id, fn($p) => $this->tracks->append($p, $data['song_ids']));
    }

    private function editTracks(Request $r, string $id, callable $edit)
    {
        $accountId = $this->playlists->account($r->user());

        return DB::connection('music')->transaction(function () use ($accountId, $id, $edit) {
            $playlist = $this->playlists->find($id, $accountId);
            abort_unless($playlist->canEditTracks($accountId), 403, '只有创建者和协作者可以修改普通歌单的歌曲。');
            $edit($playlist);

            return ['item' => $this->playlists->item($playlist, $accountId)];
        });
    }

    public function remove(Request $r, string $id)
    {
        $data = $r->validate(['entry_ids' => 'required|array|min:1|max:500', 'entry_ids.*' => 'required|integer|min:1|distinct']);

        return $this->editTracks($r, $id, fn($p) => $this->tracks->remove($p, $data['entry_ids']));
    }

    public function move(Request $r, string $id, string $entry)
    {
        $data = $r->validate(['position' => 'required|integer|min:1']);

        return $this->editTracks($r, $id, fn($p) => $this->tracks->move($p, $entry, $data['position']));
    }

    public function destroy(Request $r, string $id)
    {
        $accountId = $this->playlists->account($r->user());

        return DB::connection('music')->transaction(function () use ($accountId, $id) {
            $playlist = $this->playlists->find($id, $accountId);
            abort_unless($playlist->owner_id === $accountId, 403, '只有创建者可以删除歌单。');
            $before = $this->playlists->snapshot($playlist);
            $playlist->delete();
            $this->playlists->record($playlist, $accountId, 'deleted', $before);

            return ['message' => '歌单已删除。'];
        });
    }
}
