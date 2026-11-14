<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class StoredFile extends Base
{
    use HasUuids, SoftDeletes;

    const FIELDS        = ['id', 'user_id', 'song_id', 'kind', 'file_name', 'file_size', 'mime_type', 'sha256', 'quality', 'codec', 'bitrate', 'sample_rate', 'bit_depth', 'channels', 'duration', 'is_default', 'metadata', 'created_at', 'updated_at', 'deleted_at'];
    const UPLOAD_FIELDS = [...self::FIELDS, 'upload_context', 'upload_draft'];
    protected $table = 'files';
    protected $attributes = ['is_default' => false];
    protected $hidden = ['file_path', 'sha256', 'upload_context', 'upload_draft', 'publication'];

    public function toArray(): array
    {
        $data = parent::toArray();
        if (isset($data['metadata'])) {
            $data['metadata'] = array_intersect_key($data['metadata'], array_flip(['title', 'artist', 'artists', 'album', 'albumartist', 'year', 'date', 'track', 'disk', 'label', 'catalognumber', 'releasecountry', 'releasetype', 'musicbrainz_albumid', 'cover_file_id']));
        }

        return $data;
    }

    public function rememberUploadContext(?array $context): void
    {
        if ($context && !in_array($context, $this->upload_context ?? [], true)) {
            $this->upload_context = [...($this->upload_context ?? []), $context];
            $this->save();
        }
    }

    public function song()
    {
        return $this->belongsTo(Song::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return ['metadata' => 'array', 'upload_context' => 'array', 'upload_draft' => 'array', 'publication' => 'array', 'is_default' => 'boolean', 'duration' => 'float', 'file_size' => 'integer'];
    }
}
