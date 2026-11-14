<?php

namespace App\Models\Server;

use App\Models;

class Record extends Models\Base
{
    public $timestamps = false;
    public $incrementing = false;
    protected $connection = 'music';
    protected $keyType = 'string';

    public static function at(string $table): self
    {
        $model = new self;
        $model->setTable($table);

        return $model;
    }
}
