<?php

use App\Jobs;
use App\Models;
use App\Services;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('horizon:snapshot')->everyFiveMinutes();

Artisan::command('music:sync', function () {
    app(Services\SyncScheduler::class)->touch(full: true);
    $this->info('已安排音乐库同步。');
});
Artisan::command('music:sync-now', function () {
    app(Services\SyncScheduler::class)->touch(full: true);
    (new Jobs\SyncLibrary)->handle(app(Services\NavidromeSync::class));
    $this->info('音乐库同步完成。');
});
Schedule::job(new Jobs\SyncLibrary)->everyMinute()->withoutOverlapping();
Schedule::call(function () {
    foreach (Models\UploadSession::select(['id'])->whereIn('status', ['processing', 'publishing'])->where('updated_at', '<', now()->subMinutes(5))->limit(100)->get() as $s) {
        Jobs\ProcessUpload::dispatch($s->id);
    }
})->everyFiveMinutes()->name('recover-processing-uploads')->withoutOverlapping();
