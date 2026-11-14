<?php

namespace App\Services;

use App\Models;
use Illuminate\Auth\EloquentUserProvider;

class SessionUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->select(Models\User::AUTH_FIELDS);
    }
}
