<?php

namespace App\Services;

class FileDelivery
{
    public function response(string $path, string $mime)
    {
        $redirect = '/' . implode('/', array_map('rawurlencode', explode('/', $path)));

        return response('', 200, ['Content-Type' => $mime, 'X-Accel-Redirect' => $redirect, 'X-Content-Type-Options' => 'nosniff']);
    }
}
