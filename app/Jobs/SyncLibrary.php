<?php

namespace App\Jobs;

use App\Models;
use App\Services;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

class SyncLibrary implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 600;
    public int $uniqueFor = 660;

    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('music-library-sync'))->releaseAfter(10)->expireAfter(660)];
    }

    public function handle(Services\NavidromeSync $sync): void
    {
        $state = Models\SyncState::select(Models\SyncState::FIELDS)->find('library');
        if (!$state || $state->revision <= $state->synced_revision) {
            return;
        }
        try {
            $pending = Models\SyncState::select(['id', 'revision'])->where('id', '!=', 'library')->whereColumn('revision', '>', 'synced_revision')->get();
            $sync->run($state->synced_at ? $pending->pluck('id')->all() : null);
            foreach ($pending->chunk(500) as $changes) {
                Models\SyncState::upsert($changes->map(fn($change) => ['id' => $change->id, 'revision' => $change->revision, 'synced_revision' => $change->revision])->all(), ['id'], ['synced_revision' => DB::raw('GREATEST(sync_states.synced_revision, EXCLUDED.synced_revision)')]);
            }
            Models\SyncState::whereKey('library')->where('synced_revision', '<=', $state->revision)->update(['synced_revision' => $state->revision, 'synced_at' => now(), 'error' => null]);
        } catch (\Throwable $e) {
            $state->update(['error' => '同步暂未完成，请重试。']);
            throw $e;
        }
    }
}
