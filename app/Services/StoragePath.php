<?php

namespace App\Services;

class StoragePath
{
    public static function relative(string $path): string
    {
        $root = rtrim(config('filesystems.disks.music.root'), '/');
        if (str_starts_with($path, $root . '/')) {
            $path = substr($path, strlen($root) + 1);
        }

        return $path;
    }
}
