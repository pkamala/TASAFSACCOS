<?php

namespace App;

use Illuminate\Http\Request;

class LocalMemberSetup
{
    public function available(Request $request): bool
    {
        return config('auth.local_member_setup')
            && app()->environment('local')
            && in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)
            && in_array($request->getHost(), ['localhost', '127.0.0.1', '[::1]'], true)
            && ! $request->headers->has('Forwarded')
            && ! $request->headers->has('X-Forwarded-For');
    }
}
