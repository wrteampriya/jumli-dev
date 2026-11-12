<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Services\ResponseService;
use Exception;
use Illuminate\Http\Request;
use App\Services\Module\ModuleService;

class ModuleApiController extends Controller
{
    /**
     * Get Modules
     */
    public function getModules(Request $request)
    {
        try {
            $stats = $request->boolean('with_counts') ? ModuleService::stats() : null;

            $modules = Module::active()
                ->orderBy('sequence')
                ->get()
                ->map(function ($module) use ($stats) {
                    // Flat, resolved, cast — never the raw CSV or the
                    // absent-row/inherit distinction module_settings uses
                    // internally. See ModuleService::settings().
                    $row = [
                        'id' => $module->id,
                        'slug' => $module->slug,
                        'name' => $module->name,
                        'description' => $module->description,
                        'icon' => $module->icon,
                        'path_prefix' => $module->path_prefix,
                        'sequence' => $module->sequence,
                        'settings' => ModuleService::settings($module->id),
                    ];

                    if ($stats !== null) {
                        $row['counts'] = $stats[$module->id] ?? ['categories' => 0, 'fields' => 0, 'listings' => 0, 'packages' => 0];
                    }

                    return $row;
                });

            return ResponseService::successResponse('Modules fetched successfully', $modules);
        } catch (Exception $e) {
            return ResponseService::errorResponse('Something went wrong', $e->getMessage());
        }
    }
}
