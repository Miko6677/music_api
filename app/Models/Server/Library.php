<?php

namespace App\Models\Server;

use App\Models;

class Library extends Models\Base
{
    protected $connection = 'music';

    protected $table = 'library';
}
