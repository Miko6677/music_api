<?php

namespace App\Jobs;

use App\Models;
use App\Services;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class RefreshMetadata implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $userId, public string $type, public string $id, public string $todbId, public int $version, public array $fields)
    {
    }

    public function handle(Services\Todb $todb): void
    {
        $remote = $todb->info($this->type, $this->todbId);
        DB::transaction(function () use ($remote) {
            $class = app(Services\Catalog::class)->model($this->type);
            $e = $class::select($class::FIELDS)->lockForUpdate()->findOrFail($this->id);
            if ($e->version !== $this->version) {
                throw new \RuntimeException('本地资料已变化，请重新预览后更新。');
            }
            $duplicate = $class::select(['id'])->where('todb_id', $this->todbId)->whereKeyNot($this->id)->first();
            if ($duplicate) {
                throw new \RuntimeException('这份 todb 资料已经关联了其他条目，请先合并。');
            }
            $before = app(Services\Activity::class)->snapshot($e);
            $e->fill(array_intersect_key($remote['fields'], array_flip($this->fields)));
            $e->todb_id = $this->todbId;
            $e->source_data = $remote['source'];
            $e->metadata_updated_at = now();
            $e->version++;
            $e->save();
            $user = Models\User::select(Models\User::SESSION_FIELDS)->findOrFail($this->userId);
            app(Services\Activity::class)->record($user, 'metadata.updated', $this->type, $e, $before, '手动更新 todb #' . $this->todbId);
            Models\InboxMessage::create(['user_id' => $this->userId, 'title' => 'todb 资料已更新', 'body' => $e->name, 'link' => '/' . $this->type . '/' . $e->id]);
            app(Services\SyncScheduler::class)->touch();
        });
    }

    public function failed(?\Throwable $e): void
    {
        Models\InboxMessage::create(['user_id' => $this->userId, 'title' => '资料更新失败', 'body' => '请重新检查本地版本和 todb 关联后重试。', 'link' => '/' . $this->type . '/' . $this->id]);
    }
}
