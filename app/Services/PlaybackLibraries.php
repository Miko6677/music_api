<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Facades\DB;

class PlaybackLibraries
{
    public function settings(Models\User $user, ?array $libraryIds = null): array
    {
        $accountId = app(ServerAccount::class)->sync($user, false);
        $activeIds = Models\MediaLibrary::pluck('playback_id')->all();

        return DB::connection('music')->transaction(function () use ($accountId, $activeIds, $libraryIds) {
            $account = Models\Server\Account::select(['id'])->lockForUpdate()->findOrFail($accountId);
            $libraries = Models\Server\Library::select(['id', 'name'])->whereIn('id', $activeIds)->orderBy('name')->orderBy('id')->get();
            if ($libraryIds !== null) {
                abort_if(array_diff($libraryIds, $libraries->modelKeys()), 422, '部分媒体库已删除或尚未同步，请刷新后重新选择。');
                $account->libraries()->sync($libraryIds);
            }

            return [
                'libraries'   => $libraries,
                'library_ids' => $account->libraries()->whereIn('library.id', $libraries->modelKeys())->orderBy('library.id')->pluck('library.id')->all(),
            ];
        });
    }
}
