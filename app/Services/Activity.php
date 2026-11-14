<?php

namespace App\Services;

use App\Models;
use Illuminate\Database\Eloquent\Model;

class Activity
{
    public function record(Models\User $user, string $action, string $type, Model $entity, ?array $before = null, ?string $reason = null, ?int $restoredFrom = null): Models\ActivityLog
    {
        $after = $this->snapshot($entity);
        if ($entity instanceof Models\StoredFile) {
            foreach (array_keys($before['_defaults'] ?? []) as $songId) {
                $after['_defaults'][$songId] = Models\StoredFile::where('song_id', $songId)->where('is_default', true)->value('id');
            }
        }

        app(SyncScheduler::class)->changed($type, (string)$entity->getKey(), $before, $after);

        return Models\ActivityLog::create([
            'user_id'       => $user->id, 'action' => $action, 'entity_type' => $type, 'entity_id' => $entity->getKey(),
            'title'         => $entity->name ?? $entity->file_name ?? '资料', 'before' => $before, 'after' => $after,
            'reason'        => $reason, 'is_private' => false,
            'restored_from' => $restoredFrom,
            'playback_state' => $type === 'songs' && $action === 'merged' ? 'pending' : null,
        ]);
    }

    public function snapshot(Model $entity): array
    {
        $state = $entity->attributesToArray();
        unset($state['file_path'], $state['sha256'], $state['source_data'], $state['metadata'], $state['upload_context'], $state['upload_draft'], $state['publication']);
        if (method_exists($entity, 'artists')) {
            $state['artist_ids'] = $entity->artists()->pluck('artists.id')->all();
        }
        if ($entity instanceof Models\Song) {
            $state['lyrics'] = app(Lyrics::class)->versions($entity);
            $tracks = app(AlbumTracks::class)->entries($entity);
            $names = Models\Album::withTrashed()->select(['id', 'name'])->whereIn('id', array_column($tracks, 'album_id'))->pluck('name', 'id');
            $state['albums'] = array_map(fn($track) => [...$track, 'name' => $names->get($track['album_id'], '专辑')], $tracks);
            $state['library_name'] = $entity->library()->value('name');
        }

        if ($entity instanceof Models\StoredFile) {
            $state['_defaults'] = $entity->song_id ? [$entity->song_id => Models\StoredFile::where('song_id', $entity->song_id)->where('is_default', true)->value('id')] : [];
        }

        return $state;
    }
}
