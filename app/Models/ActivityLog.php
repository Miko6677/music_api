<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class ActivityLog extends Base
{
    const        FIELDS           = ['id', 'user_id', 'action', 'entity_type', 'entity_id', 'title', 'reason', 'before', 'after', 'is_private', 'restored_from', 'created_at'];
    public const DEFAULT_CATEGORY = 'without_uploads';
    public const CATEGORY_ACTIONS = [
        'upload'   => ['published'],
        'create'   => ['created'],
        'update'   => ['updated', 'metadata.updated', 'role.updated'],
        'organize' => ['merged'],
        'delete'   => ['deleted'],
        'restore'  => ['restored'],
        'other'    => [],
    ];
    public $timestamps = false;

    public function scopeOrganized(Builder $query): void
    {
        $query->where(fn($actions) => $actions->whereIn('action', self::CATEGORY_ACTIONS['organize'])
            ->orWhere(fn($updates) => $updates->where('action', 'updated')->where(function ($entities) {
                foreach (['songs' => ['albums', 'library_id'], 'files' => ['song_id', 'is_default']] as $type => $fields) {
                    $entities->orWhere(fn($entity) => $entity->where('entity_type', $type)->where(function ($changes) use ($fields) {
                        foreach ($fields as $field) {
                            $changes->orWhereColumn('before->' . $field, '!=', 'after->' . $field);
                        }
                    }));
                }
            })));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'playback_snapshot' => 'array', 'is_private' => 'boolean', 'created_at' => 'datetime'];
    }
}
