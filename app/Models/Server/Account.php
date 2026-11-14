<?php

namespace App\Models\Server;

use App\Models;

class Account extends Models\Base
{
    const PUBLIC_FIELDS = ['id', 'user_name', 'name'];
    public $incrementing = false;
    protected $connection = 'music';
    protected $table = 'app_user';
    protected $keyType = 'string';

    public function libraries()
    {
        return $this->belongsToMany(Library::class, 'user_library', 'user_id', 'library_id');
    }
}
