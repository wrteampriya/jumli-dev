<?php

namespace App\Http\Middleware;

use App\Models\Module;
use Closure;
use Illuminate\Http\Request;
use App\Services\Module\ModuleService;

// Reads the X-Module header once per API request and stores the module id on
// the request, where ModuleService::currentId() picks it up. No header = no module
// filter, so clients that never send it get unchanged responses.
class ResolveApiModule
{
    public function handle(Request $request, Closure $next)
    {
        $slug = $request->header('X-Module');

        if (empty($slug)) {
            return $next($request);
        }

        $moduleId = Module::active()->where('slug', $slug)->value('id');

        if (!$moduleId) {
            return response()->json([
                'error' => true,
                'message' => 'Invalid X-Module header.',
                'data' => null,
                'code' => config('constants.RESPONSE_CODE.VALIDATION_ERROR'),
            ], 422);
        }

        $request->attributes->set('module_id', $moduleId);

        return $next($request);
    }
}
