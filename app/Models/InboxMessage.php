<?php

namespace App\Models;

class InboxMessage extends Base
{
    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
