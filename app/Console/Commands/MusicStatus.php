<?php

namespace App\Console\Commands;

use App\Models;
use Illuminate\Console\Command;

class MusicStatus extends Command
{
    protected $signature = 'music:status';

    protected $description = 'Show music database, storage and production process readiness without starting services';

    public function handle(): int
    {
        $this->info('跃律 · 当前状态');
        try {
            $this->table(['数据库', '歌曲', '专辑', '音乐人', '伙伴'], [['共建库', Models\Song::count(), Models\Album::count(), Models\Artist::count(), Models\User::count()], ['播放库', Models\Server\Record::at('media_file')->where('missing', false)->count(), Models\Server\Record::at('album')->where('missing', false)->count(), Models\Server\Record::at('artist')->where('missing', false)->count(), Models\Server\Record::at('app_user')->count()]]);
        } catch (\Throwable $e) {
            $this->error('数据库尚未就绪，请先完成两个服务的迁移。');

            return self::FAILURE;
        }
        $state = Models\SyncState::select(Models\SyncState::FIELDS)->find('library');
        $this->line('同步：' . (!$state || $state->revision <= $state->synced_revision ? '已跟上' : '等待队列处理'));
        foreach (['API' => 8000, 'tusd' => 4060, 'Navidrome' => 4533] as $name => $port) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, .2);
            $this->line($name . '：' . ($socket ? '端口可连接' : '未启动'));
            if ($socket) {
                fclose($socket);
            }
        }
        $this->line('上传目录：' . (is_writable(config('services.tusd.path')) ? '可写' : '请检查权限'));
        $this->line('解析服务：' . (filled(config('services.metadata.global')) ? '已配置' : '未配置；音频上传解析不可用'));
        $this->line('todb：' . (filled(config('services.todb.url')) ? '已配置' : '未配置；可正常维护本地资料'));

        return self::SUCCESS;
    }
}
