<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\ModuleSettingDefinition;
use App\Models\ModuleSettingValue;
use App\Models\PluginLicense;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;
use App\Services\Module\ModuleService;

// The install-only "Modules" admin page — see docs/module-plugin-contract.md.
// Deliberately has no create/store route: a module row only ever comes from
// the v3.2.0 migration (classified) or a plugin install
// (App\Services\Plugin\ModuleInstaller). Identity fields (name/description/icon/sequence) live on
// the modules table itself; everything else (listing actions, pricing,
// monetization, media) is a module_setting_values row, read/written through
// ModuleService — never raw arrays here.
class ModuleController extends Controller
{
    private string $uploadFolder = 'modules';

    public function index()
    {
        ResponseService::noAnyPermissionThenRedirect(['module-list', 'module-update']);

        // Paginated so this stays usable if module count grows well past a
        // handful — a no-op today (the pagination links render nothing at
        // all below one page). Tab counts are deliberately NOT derived from
        // $modules: with pagination it only holds the current page's rows,
        // so All/Active/Inactive are counted with their own cheap queries
        // instead of $modules->count()/$modules->where(...).
        $modules = Module::orderBy('sequence')->paginate(20);

        $moduleCounts = [
            'all' => Module::count(),
            'active' => Module::where('status', true)->count(),
            'inactive' => Module::where('status', false)->count(),
        ];

        $stats = ModuleService::stats();

        // Deactivate is disabled on the last active module.
        $activeModuleIds = Module::active()->pluck('id');

        $licenses = PluginLicense::forDomain()
            ->whereIn('plugin_slug', $modules->pluck('plugin_slug')->filter())
            ->get()
            ->keyBy('plugin_slug');

        return view('modules.index', compact('modules', 'moduleCounts', 'stats', 'licenses', 'activeModuleIds'));
    }

    public function edit($id)
    {
        ResponseService::noPermissionThenRedirect('module-update');

        $module = Module::findOrFail($id);
        // Each field shows the module's own value, or the default when the
        // module has none.
        $formSettings = ModuleService::settings($module->id);
        $pricingModeOptions = ModuleService::pricingModes();

        return view('modules.edit', compact('module', 'formSettings', 'pricingModeOptions'));
    }

    public function update(Request $request, $id)
    {
        // dd($request->all());
        ResponseService::noPermissionThenSendJson('module-update');

        // The dropdowns may only pick one of the options checked above them.
        $pricingModes = (array) $request->input('pricing_modes', []);

        // Validated outside the try: a ValidationException caught by the
        // Throwable handler below would surface as a generic "Error Occurred".
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:1000',
            'icon' => 'nullable|mimes:jpg,jpeg,png|max:2048',
            'sequence' => 'nullable|integer|min:0',

            'pricing_modes' => 'required|array|min:1',
            'pricing_modes.*' => Rule::in(array_keys(ModuleService::pricingModes())),
            'default_pricing_mode' => ['nullable', Rule::in($pricingModes)],
            'ad_packages_enabled' => 'nullable|boolean',
            'feature_packages_enabled' => 'nullable|boolean',
            'product_video' => 'nullable|boolean',
            'max_images' => 'required|integer|min:1|max:50',
            // Not sent while product videos are not allowed — the stored value is kept.
            'video_max_file_size_mb' => 'required_if_accepted:product_video|nullable|integer|min:1|max:500',
        ], [
            'pricing_modes.required' => 'Select at least one pricing mode.',
            'default_pricing_mode.in' => 'The default pricing mode must be one of the selected pricing modes.',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $module = Module::findOrFail($id);

            $data = [
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'sequence' => $request->input('sequence', $module->sequence),
            ];

            // No file means "keep the current icon"; a new upload replaces
            // (and deletes) the old file.
            if ($request->hasFile('icon')) {
                $oldIconPath = $module->getRawOriginal('icon');
                $iconPath = FileService::compressAndReplace($request->file('icon'), $this->uploadFolder, $oldIconPath);

                if (!$iconPath) {
                    return ResponseService::errorResponse('Failed to upload the module icon.');
                }

                $data['icon'] = $iconPath;
            }

            $module->update($data);

            $this->saveModuleSettings($module, $request);
            HelperService::revalidateSiteConfig();

            ResponseService::successResponse('Module updated successfully.');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'ModuleController -> update');
            ResponseService::errorResponse($e->getMessage());
        }
    }

    // Only values that differ from the definition's default are stored; a
    // value equal to the default deletes the module's row so it keeps
    // following the default.
    protected function saveModuleSettings(Module $module, Request $request): void
    {
        // An omitted default pricing mode (e.g. the form disabled its select)
        // falls back to the first checked option — never to one the module
        // doesn't support.
        $pricingModes = array_values((array) $request->input('pricing_modes', []));
        $defaultPricingMode = $request->input('default_pricing_mode');
        if (!in_array($defaultPricingMode, $pricingModes, true)) {
            $defaultPricingMode = $pricingModes[0] ?? 'fixed';
        }

        $values = [
            'pricing_modes' => implode(',', $pricingModes),
            'default_pricing_mode' => $defaultPricingMode,
            'ad_packages_enabled' => $request->boolean('ad_packages_enabled') ? '1' : '0',
            'feature_packages_enabled' => $request->boolean('feature_packages_enabled') ? '1' : '0',
            'product_video' => $request->boolean('product_video') ? '1' : '0',
            // Cast so "+5" is stored (and compared to the default) as "5".
            'max_images' => (string) (int) $request->input('max_images'),
        ];
        if ($request->filled('video_max_file_size_mb')) {
            $values['video_max_file_size_mb'] = (string) (int) $request->input('video_max_file_size_mb');
        }

        // dd($module->toArray(), $request->toArray());
        $definitions = ModuleSettingDefinition::whereIn('name', array_keys($values))->get()->keyBy('name');

        foreach ($values as $name => $value) {
            $definition = $definitions->get($name);
            if (!$definition) {
                continue;
            }

            if ($value === $definition->default_value) {
                ModuleSettingValue::where('module_id', $module->id)
                    ->where('module_setting_definition_id', $definition->id)
                    ->delete();
                continue;
            }

            ModuleSettingValue::updateOrCreate(
                ['module_id' => $module->id, 'module_setting_definition_id' => $definition->id],
                ['value' => $value]
            );
        }

        ModuleService::clearSettingsCache($module->id);
    }

    public function destroy($id)
    {
        ResponseService::noPermissionThenSendJson('module-update');

        try {
            $module = Module::withCount('categories')->findOrFail($id);

            // Belt-and-suspenders with Module::booted()'s Eloquent guard and
            // the categories.module_id FK's restrictOnDelete — this is the
            // one that gives a readable message instead of a raw exception.
            if ($module->status && $module->isLastActive()) {
                return ResponseService::errorResponse(__('At least one module must stay active'));
            }

            if ($module->categories_count > 0) {
                return ResponseService::errorResponse('Cannot delete a module that still has categories. Reassign or remove them first.');
            }

            // Only the row goes; a plugin-installed module keeps its files
            // and license (disable it in Plugin Manager to hide it instead).
            // Re-installing or updating the plugin re-creates the row.
            $iconPath = $module->getRawOriginal('icon');
            $module->delete();

            if ($iconPath) {
                FileService::delete($iconPath);
            }

            ModuleService::clearStatsCache();
            HelperService::revalidateSiteConfig();

            ResponseService::successResponse('Module deleted successfully.');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'ModuleController -> destroy');
            ResponseService::errorResponse($e->getMessage());
        }
    }
}
