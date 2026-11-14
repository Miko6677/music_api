<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireNotice
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()->notice_version === config('contribution.notice.version'), 403, '请先阅读并同意上传须知。');

        return $next($request);
    }
}
