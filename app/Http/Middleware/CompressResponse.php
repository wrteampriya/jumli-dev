<?php

namespace App\Http\Middleware;

use Closure;

class CompressResponse
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        if (extension_loaded('zlib') && str_contains($request->header('Accept-Encoding'), 'gzip')) {
            $content = $response->getContent();
            $compressedContent = gzencode($content, 9);

            $response->setContent($compressedContent);
            $response->headers->set('Content-Encoding', 'gzip');
            $response->headers->set('Content-Length', strlen($compressedContent));
        }

        return $response;
    }
}
