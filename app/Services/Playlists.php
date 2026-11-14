<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Playlists
{
    public function item(Models\Server\Playlist $playlist, string $accountId): array
    {
        return $this->present($playlist->newCollection([$playlist]), $accountId)->first();
    }

    public function present(Collection $playlists, string $accountId): Collection
    {
        $playlists->loadMissing(['owner:' . implode(',', Models\Server\Account::PUBLIC_FIELDS), 'collaborators:' . implode(',', Models\Server\Account::PUBLIC_FIELDS)]);
        $playlists->loadCount('entries');
        $accounts = $playlists->flatMap(fn($p) => $p->collaborators->concat([$p->owner]))->filter()->unique('id');
        $profiles = $this->profiles($accounts);

        return $playlists->map(function ($p) use ($profiles, $accountId) {
            $owner = $profiles->get($p->owner_id);
            $members = $p->collaborators;

            return [
                'id'                => $p->id, 'name' => $p->name, 'description' => $p->comment, 'is_public' => $p->public,
                'cover_id'          => $p->cover_id,
                'user_id'           => $owner['id'] ?? 0, 'owner' => $owner,
                'collaborators'     => $members->map(fn($m) => $profiles->get($m->id))->values(),
                'songs_count'       => $p->entries_count, 'duration' => $p->duration,
                'can_edit_metadata' => $p->owner_id === $accountId,
                'can_edit_tracks'   => !$p->rules && !$p->sync && ($p->owner_id === $accountId || $members->contains('id', $accountId)),
                'created_at'        => $p->created_at, 'updated_at' => $p->updated_at, 'deleted_at' => $p->deleted_at,
            ];
        });
    }

    public function profiles(Collection $accounts): Collection
    {
        $users = Models\User::select([...Models\User::PUBLIC_FIELDS, 'emos_id'])->whereIn('emos_id', $accounts->pluck('id')->unique())->get()->keyBy('emos_id');

        return $accounts->mapWithKeys(function ($account) use ($users) {
            $user = $users->get($account->id);

            return [$account->id => $user ? $user->only(Models\User::PUBLIC_FIELDS) : ['id' => 0, 'username' => $account->user_name, 'pseudonym' => $account->name, 'avatar' => null]];
        });
    }

    public function save(string $accountId, array $data, ?string $id): Models\Server\Playlist
    {
        $playlist = $id ? $this->find($id, $accountId) : new Models\Server\Playlist(['id' => '0' . Str::random(21), 'owner_id' => $accountId]);
        abort_if($id && $playlist->owner_id !== $accountId, 403, '只有创建者可以修改歌单资料和协作者。');
        $before = $id ? $this->snapshot($playlist) : null;
        $playlist->fill(['name' => $data['name'], 'comment' => $data['description'] ?? '', 'public' => $data['is_public'], 'cover_id' => $data['cover_id'] ?? null]);
        $coverChanged = $playlist->isDirty('cover_id');
        $playlist->save();
        $members = [];
        foreach (Models\User::select(Models\User::SESSION_FIELDS)->whereIn('id', $data['collaborator_ids'])->get() as $member) {
            $memberId = $this->account($member);
            if ($memberId !== $accountId) {
                $members[] = $memberId;
            }
        }
        $playlist->collaborators()->sync($members);
        $playlist->unsetRelations();
        if ($coverChanged) {
            $this->syncCover($playlist);
        }
        $this->record($playlist, $accountId, $id ? 'updated' : 'created', $before);

        return $playlist;
    }

    public function find(string $id, string $accountId): Models\Server\Playlist
    {
        return Models\Server\Playlist::select(Models\Server\Playlist::FIELDS)->visibleTo($accountId)->findOrFail($id);
    }

    public function snapshot(Models\Server\Playlist $playlist): array
    {
        return [
            'name'             => $playlist->name, 'description' => $playlist->comment, 'is_public' => $playlist->public,
            'cover_id'         => $playlist->cover_id, 'deleted_at' => $playlist->deleted_at?->toISOString(),
            'collaborator_ids' => $playlist->collaborators()->orderBy('app_user.id')->pluck('app_user.id')->all(),
        ];
    }

    public function account(Models\User $user): string
    {
        return app(ServerAccount::class)->sync($user, false);
    }

    private function syncCover(Models\Server\Playlist $playlist): void
    {
        $file = $playlist->cover_id ? Models\StoredFile::select(['file_path', 'mime_type'])->where('kind', 'cover')->find($playlist->cover_id) : null;
        if ($file) {
            Models\Server\Record::at('music_cover')->upsert([['id' => $playlist->id, 'path' => $file->file_path, 'mime_type' => $file->mime_type, 'url' => '']], ['id'], ['path', 'mime_type', 'url']);
        } else {
            Models\Server\Record::at('music_cover')->whereKey($playlist->id)->delete();
        }
    }

    public function record(Models\Server\Playlist $playlist, string $actorId, string $action, ?array $before): void
    {
        Models\Server\PlaylistActivity::create([
            'playlist_id' => $playlist->id, 'actor_id' => $actorId, 'action' => $action, 'title' => $playlist->name,
            'before'      => $before, 'after' => $this->snapshot($playlist),
            'is_private'  => !$playlist->public || (($before['is_public'] ?? true) === false), 'created_at' => now(),
        ]);
    }
}
