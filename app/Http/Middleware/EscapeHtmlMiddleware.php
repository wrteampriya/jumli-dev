<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class EscapeHtmlMiddleware
{
    /**
     * Fields that should NOT be escaped/decoded
     */
    protected $except = [
        'privacy_policy',
        'terms_conditions',
        'about_us',
        'refund_policy',
        'contact_us',
        'blog_description'
    ];

    /**
     * Check if the given key (or any of its parents) should be skipped.
     */
    protected function shouldSkip($keyPath)
    {
        foreach ($this->except as $except) {
            if (Str::startsWith($keyPath, $except)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Recursively escape input values
     */
    protected function escape($value, $keyPath = '')
    {
        if (is_array($value)) {
            $escaped = [];
            foreach ($value as $k => $v) {
                $newPath = $keyPath ? "{$keyPath}.{$k}" : $k;
                $escaped[$k] = $this->escape($v, $newPath);
            }
            return $escaped;
        }

        if ($this->shouldSkip($keyPath)) {
            return $value;
        }

        if (is_string($value)) {
            // Skip JSON strings
            json_decode($value);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }

            // Escape HTML
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $value;
    }

    /**
     * Recursively decode output values
     */
    protected function decode($value, $keyPath = '')
    {
        if (is_array($value)) {
            $decoded = [];
            foreach ($value as $k => $v) {
                $newPath = $keyPath ? "{$keyPath}.{$k}" : $k;
                $decoded[$k] = $this->decode($v, $newPath);
            }
            return $decoded;
        }

        if ($this->shouldSkip($keyPath)) {
            return $value;
        }

        if (is_string($value)) {
            return html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $value;
    }

    /**
     * Handle request and response
     */
    public function handle($request, Closure $next)
    {
        // ✅ Step 1: Escape incoming request data
        $escaped = [];
        foreach ($request->all() as $key => $value) {
            $escaped[$key] = $this->escape($value, $key);
        }
        $request->merge($escaped);

        // Proceed with request
        $response = $next($request);

        // ✅ Step 2: Decode outgoing JSON responses
        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            $decodedData = $this->decode($data);
            $response->setData($decodedData);
        }

        return $response;
    }
}
