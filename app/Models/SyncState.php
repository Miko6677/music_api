<?php

namespace App\Models;

class SyncState extends Base
{
    const FIELDS = ['id', 'revision', 'synced_revision', 'synced_at', 'error'];
    public $incrementing = false;
    protected $keyType = 'string';
}
