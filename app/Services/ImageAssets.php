<?php

namespace App\Services;

use App\Models;

class ImageAssets
{
    public function references(string $id, Models\User $viewer): array
    {
        $references = [];
        foreach (Catalog::TYPES as $model) {
            $query = $model::withTrashed()->select(['id', 'name', 'deleted_at'])->where('cover_id', $id);
            if ($model === Models\Album::class) {
                $query->orWhereJsonContains('artwork', ['file_id' => $id]);
            }
            foreach ($query->limit(6)->get() as $entity) {
                $references[] = $entity->name . ($entity->trashed() ? '（已删除）' : '');
            }
        }
        $playlists = Models\Server\Playlist::withTrashed()->where('cover_id', $id);
        foreach ((clone $playlists)->select(['id', 'name'])->visibleTo($viewer->emos_id)->limit(6)->get() as $playlist) {
            $references[] = $playlist->name;
        }
        if ((clone $playlists)->whereNot(fn($query) => $query->visibleTo($viewer->emos_id))->exists()) {
            $references[] = '无权查看的歌单';
        }
        if (Models\StoredFile::where('metadata->cover_file_id', $id)->exists()) {
            $references[] = '音频内嵌封面';
        }
        $drafts = Models\StoredFile::whereNull('song_id')->where(function ($query) use ($id) {
            $query->where('upload_draft->data->album->cover_id', $id)
                ->orWhere('upload_draft->data->album_supplement->cover_id', $id)
                ->orWhereJsonContains('upload_draft->data->album->artwork', ['file_id' => $id])
                ->orWhereJsonContains('upload_draft->data->album_supplement->artwork', ['file_id' => $id]);
        });
        foreach ((clone $drafts)->select(['id', 'file_name'])->where('user_id', $viewer->id)->limit(6)->get() as $file) {
            $references[] = $file->file_name . ' 的整理草稿';
        }
        if ((clone $drafts)->where('user_id', '!=', $viewer->id)->exists()) {
            $references[] = '其他成员的整理草稿';
        }

        return array_values(array_unique($references));
    }
}
