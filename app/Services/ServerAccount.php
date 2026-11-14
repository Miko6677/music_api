<?php

namespace App\Services;

use App\Models;
use Illuminate\Support\Facades\DB;

class ServerAccount
{
    public function sync(Models\User $user, bool $overwrite = true, ?string $password = null): string
    {
        Models\MediaLibrary::ensureDefault($user);

        return DB::connection('music')->transaction(function () use ($user, $overwrite, $password) {
            $id = $user->emos_id;
            // Serialize account creation with music_server's EMOS login flow.
            DB::connection('music')->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['emos-user:' . $id]);
            $existing = Models\Server\Account::select(['id'])->find($id);
            $data = ['user_name' => $user->username, 'name' => $user->pseudonym ?: $user->username, 'updated_at' => now()];
            if ($existing) {
                $updates = $overwrite ? $data : [];
                if ($password !== null) {
                    $updates['password'] = $password;
                }
                if ($updates !== []) {
                    $existing->update($updates);
                }
            } else {
                $account = Models\Server\Account::create($data + ['id' => $id, 'password' => $password ?? '', 'is_admin' => false, 'email' => '', 'created_at' => now()]);
                $libraryIds = Models\Server\Library::where('default_new_users', true)->pluck('id')->all();
                $account->libraries()->sync($libraryIds);
            }
            $quality = ['user_id' => $id, 'key' => 'music_quality', 'value' => $user->quality];
            if ($overwrite) {
                Models\Server\Record::at('user_props')->upsert([$quality], ['user_id', 'key'], ['value']);
            } else {
                Models\Server\Record::at('user_props')->insertOrIgnore([$quality]);
            }

            return $id;
        });
    }
}
