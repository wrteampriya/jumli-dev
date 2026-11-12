<?php

namespace App\Http\Controllers;

use App\Models\PaymentConfiguration;
use App\Models\PluginLicense;
use App\Models\Setting;
use App\Services\HelperService;
use App\Services\License\LicenseDomain;
use App\Services\License\LicenseInstaller;
use App\Services\License\MarketplaceCatalogService;
use App\Services\Plugin\ModuleInstaller;
use App\Services\Plugin\ModulePackageValidator;
use App\Services\Plugin\PluginManifest;
use App\Services\ResponseService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;
use App\Services\Module\ModuleService;

class PluginManagerController extends Controller
{
    public function index(MarketplaceCatalogService $catalog)
    {
        if (!Auth::user()->hasRole('Super Admin')) {
            return redirect(route('home'))->withErrors([
                'message' => trans("You Don't have enough permissions"),
            ]);
        }

        $domain = LicenseDomain::current();

        // Installed = licensed on this domain, so a plugin never shows in both
        // Installed and Available.
        $installedPlugins = PluginLicense::forDomain($domain)
            ->select('plugin_slug', 'type', 'version', 'is_enabled')
            ->orderBy('plugin_slug')
            ->get()
            ->all();

        $notInstalled = $this->notInstalledCatalogItems($catalog, $domain);

        $catalogLastFetched = Setting::getValue('plugin_catalog_last_fetched');

        return view('plugin-manager.index', compact('installedPlugins', 'domain', 'notInstalled', 'catalogLastFetched'));
    }

    // Catalog entries with no license on this domain — "you could buy this".
    protected function notInstalledCatalogItems(MarketplaceCatalogService $catalog, string $domain): array
    {
        $licensedSlugs = PluginLicense::forDomain($domain)->pluck('plugin_slug')->all();

        return collect($catalog->all())
            ->reject(fn ($item) => in_array($item['slug'], $licensedSlugs, true))
            ->values()
            ->all();
    }

    public function installed()
    {
        if (!Auth::user()->hasRole('Super Admin')) {
            ResponseService::errorResponse(trans("You Don't have enough permissions"));

            return;
        }

        $domain = LicenseDomain::current();

        $plugins = PluginLicense::forDomain($domain)
            ->where('is_enabled', true)
            ->where('revoked', false)
            ->get()
            ->map(fn ($license) => [
                'slug' => $license->plugin_slug,
                'type' => $license->type,
                'version' => $license->version,
                'scope' => $license->scope,
                'verified_at' => optional($license->verified_at)->toDateTimeString(),
            ])
            ->values();

        ResponseService::successResponse('Installed plugins fetched successfully.', $plugins);
    }

    public function toggleEnabled(string $slug)
    {
        if (!Auth::user()->hasRole('Super Admin')) {
            ResponseService::errorResponse(trans("You Don't have enough permissions"));

            return;
        }

        $domain = LicenseDomain::current();
        $license = PluginLicense::forDomain($domain)->where('plugin_slug', $slug)->first();

        if (!$license) {
            ResponseService::errorResponse('Plugin license not found.');

            return;
        }

        $license->update(['is_enabled' => !$license->is_enabled]);

        if (!$license->is_enabled && $license->type === 'payment') {
            PaymentConfiguration::where('payment_method', $license->plugin_slug)->update(['status' => 0]);
        }

        if ($license->type === 'module') {
            ModuleService::clearStatsCache();
            HelperService::revalidateSiteConfig();
        }

        ResponseService::successResponse(
            $license->is_enabled ? "Plugin [{$slug}] enabled." : "Plugin [{$slug}] disabled.",
            ['is_enabled' => $license->is_enabled]
        );
    }

    // Manual "Sync Plugin" refresh, throttled at the route (5/hour) — busts the
    // 1-hour catalog cache and re-fetches from the validator immediately.
    public function refreshCatalog(MarketplaceCatalogService $service)
    {
        if (!Auth::user()->hasRole('Super Admin')) {
            ResponseService::errorResponse(trans("You Don't have enough permissions"));
        }

        try {
            $result = $service->refresh();
            $domain = LicenseDomain::current();
            $notInstalled = $this->notInstalledCatalogItems($service, $domain);

            ResponseService::successResponse("Catalog refreshed", [
                    'notInstalled' => $notInstalled,
                    'lastFetched' => Setting::getValue('plugin_catalog_last_fetched'),
                ]);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'PluginManagerController -> refreshCatalog');
            ResponseService::errorResponse('Catalog refresh failed: '.$e->getMessage());
        }
    }

    // Pre-fills the slug from the catalog item clicked on the Available
    // Plugin tab, or blank for a manual install.
    public function installForm(MarketplaceCatalogService $catalog, ?string $slug = null)
    {
        if (!Auth::user()->hasRole('Super Admin')) {
            return redirect(route('home'))->withErrors([
                'message' => trans("You Don't have enough permissions"),
            ]);
        }

        $catalogItem = $slug ? $catalog->find($slug) : null;

        // Fall back to the local module.json's name if there's no catalog entry yet.
        $displayName = $catalogItem['name'] ?? null;
        if (!$displayName && $slug) {
            $displayName = PluginManifest::read($slug)['name'] ?? null;
        }

        return view('plugin-manager.install', compact('slug', 'catalogItem', 'displayName'));
    }

    // Single combined step: verify the purchase code FIRST; only on success
    // do we touch the zip. A failed license check never extracts anything.
    public function install(Request $request, LicenseInstaller $installer, MarketplaceCatalogService $catalog)
    {
        if (!Auth::user()->hasRole('Super Admin')) {
            ResponseService::errorResponse(trans("You Don't have enough permissions"));
        }

        $validator = Validator::make($request->all(), [
            'slug' => 'required|string|regex:/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/',
            'purchase_code' => 'required|string',
            'file' => 'required|file|mimes:zip',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        $slug = $request->input('slug');
        $scope = $catalog->find($slug)['scope'] ?? null;
        $domain = LicenseDomain::current();

        try {
            $installer->verify($slug, $request->input('purchase_code'), $domain);
        } catch (Throwable $e) {
            ResponseService::errorResponse('License verification failed: '.$e->getMessage());
        }

        try {
            $this->extractPluginZip($slug, $request->file('file'));
            $license = $installer->save($slug, $request->input('purchase_code'), $domain, $scope);

            if ($license->type === 'module') {
                $this->installModule($license, $slug);
            }

            Artisan::call('optimize:clear');

            if ($license->type === 'module') {
                HelperService::revalidateSiteConfig();
            }

            ResponseService::successResponse("Plugin [{$slug}] activated and installed.");
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'PluginManagerController -> install (extract)');
            ResponseService::errorResponse('License verified, but package extraction failed: '.$e->getMessage());
        }
    }

    // Update form: same fields as install (purchase code + zip), slug locked
    // to an already-installed plugin.
    public function updateForm(string $slug)
    {
        if (!Auth::user()->hasRole('Super Admin')) {
            return redirect(route('home'))->withErrors([
                'message' => trans("You Don't have enough permissions"),
            ]);
        }

        $license = PluginLicense::forDomain()->where('plugin_slug', $slug)->first();

        if (!$license) {
            return redirect(route('plugin-manager.index'))->withErrors([
                'message' => trans('Plugin is not installed — install it first.'),
            ]);
        }

        $catalogItem = null;
        $displayName = PluginManifest::read($slug)['name'] ?? $license->displayName();
        $installedVersion = PluginManifest::read($slug)['version'] ?? $license->version;
        $isUpdate = true;

        return view('plugin-manager.install', compact('slug', 'catalogItem', 'displayName', 'installedVersion', 'isUpdate'));
    }

    // Same order as install: re-verify the purchase code FIRST, then check
    // the zip (slug + strictly newer version) and overwrite in place.
    public function update(Request $request, string $slug, LicenseInstaller $installer)
    {
        if (!Auth::user()->hasRole('Super Admin')) {
            ResponseService::errorResponse(trans("You Don't have enough permissions"));
        }

        $validator = Validator::make($request->all(), [
            'purchase_code' => 'required|string',
            'file' => 'required|file|mimes:zip',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        $domain = LicenseDomain::current();
        $license = PluginLicense::forDomain($domain)->where('plugin_slug', $slug)->first();

        if (!$license) {
            ResponseService::errorResponse("Plugin [{$slug}] is not installed — install it first.");
        }

        if ($license->revoked) {
            ResponseService::errorResponse("Plugin [{$slug}] license has been revoked.");
        }

        $fromVersion = PluginManifest::read($slug)['version'] ?? $license->version;

        try {
            $installer->verify($slug, $request->input('purchase_code'), $license->domain);
        } catch (Throwable $e) {
            ResponseService::errorResponse('License verification failed: '.$e->getMessage());
        }

        try {
            $this->extractPluginZip($slug, $request->file('file'), true);
            $license = $installer->save($slug, $request->input('purchase_code'), $license->domain, $license->scope ?? 'universal');

            if ($license->type === 'module') {
                app(ModuleInstaller::class)->install($slug);
            }

            Artisan::call('optimize:clear');

            if ($license->type === 'module') {
                HelperService::revalidateSiteConfig();
            }

            $toVersion = $license->version;
            ResponseService::successResponse("Plugin [{$slug}] updated from v{$fromVersion} to v{$toVersion}.");
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, 'PluginManagerController -> update (extract)');
            ResponseService::errorResponse('License verified, but the update failed: '.$e->getMessage());
        }
    }

    // A first install whose module setup fails (e.g. a plugin migration)
    // drops the new license again, so the plugin is back under Available
    // and can simply be installed again.
    protected function installModule(PluginLicense $license, string $slug): void
    {
        try {
            app(ModuleInstaller::class)->install($slug);
        } catch (Throwable $e) {
            if ($license->wasRecentlyCreated) {
                $license->delete();
            }

            throw $e;
        }
    }

    // Install/update zip: contents extract straight into app/Modules/{slug}/,
    // overwriting existing files. Every check runs before anything is
    // extracted, so a rejected zip never touches the installed plugin.
    // $isUpdate additionally requires the plugin's current module.json to
    // exist and the zip's version to be strictly newer than it.
    protected function extractPluginZip(string $slug, $uploadedFile, bool $isUpdate = false): void
    {
        $tmpPath = storage_path('app/plugin-uploads');
        File::ensureDirectoryExists($tmpPath);

        $fileName = uniqid('plugin_').'.zip';
        $uploadedFile->move($tmpPath, $fileName);
        $filePath = $tmpPath.'/'.$fileName;

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            unlink($filePath);
            throw new \RuntimeException('Unable to open uploaded zip.');
        }

        $fail = function (string $message) use ($zip, $filePath): never {
            $zip->close();
            unlink($filePath);
            throw new \RuntimeException($message);
        };

        // Zip-slip guard: reject any entry that would escape the destination
        // (../, absolute paths) before extracting anything.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false || str_contains($entry, '..') || str_starts_with($entry, '/')) {
                $fail("Unsafe path in zip entry: {$entry}");
            }
        }

        // Flat (module.json at the zip root) or wrapped in a top-level
        // {slug}/ folder — wrapped zips extract one level up to avoid
        // double-nesting into app/Modules/{slug}/{slug}/.
        $isWrapped = $zip->locateName("{$slug}/module.json") !== false;
        $isFlat = $zip->locateName('module.json') !== false;

        if (!$isWrapped && !$isFlat) {
            $fail("Zip does not contain module.json — not a valid plugin package for slug [{$slug}].");
        }

        $manifestEntry = $isWrapped ? "{$slug}/module.json" : 'module.json';
        $manifestContent = $zip->getFromName($manifestEntry);
        $manifest = $manifestContent !== false ? json_decode($manifestContent, true) : null;

        if (!is_array($manifest)) {
            $fail('module.json is missing or not valid JSON.');
        }

        // Plugin discovery skips any folder whose module.json slug differs
        // from the folder name, so a mismatched package would install dead.
        if (($manifest['slug'] ?? null) !== $slug) {
            $fail("module.json slug [".($manifest['slug'] ?? '')."] does not match plugin [{$slug}].");
        }

        if ($isUpdate) {
            $current = PluginManifest::read($slug);

            if (!$current || ($current['slug'] ?? null) !== $slug) {
                $fail("Installed plugin [{$slug}] has no valid module.json — reinstall it instead of updating.");
            }

            $currentVersion = (string) ($current['version'] ?? '');
            $newVersion = (string) ($manifest['version'] ?? '');

            if ($newVersion === '') {
                $fail('module.json in the zip must declare "version".');
            }

            // Same rule as the system update: only a strictly newer version.
            if ($currentVersion !== '') {
                $comparison = version_compare($newVersion, $currentVersion);

                if ($comparison === 0) {
                    $fail("Plugin [{$slug}] is already up to date (v{$currentVersion}).");
                }

                if ($comparison < 0) {
                    $fail("Uploaded v{$newVersion} is older than installed v{$currentVersion}.");
                }
            }
        }

        if (($manifest['type'] ?? null) === 'module' && ($error = ModulePackageValidator::validate($manifest, $slug))) {
            $fail($error);
        }

        // main_class names the one class this plugin needs to work (its
        // ServiceProvider, or its own service class). Required for every
        // type except "module": a module can be data only (module.json +
        // icon) — ModuleInstaller builds it from the manifest. A declared
        // main_class is checked the same way for every type.
        $mainClass = $manifest['main_class'] ?? null;

        if (!$mainClass && ($manifest['type'] ?? null) !== 'module') {
            $fail('module.json must declare "main_class" — the class this plugin requires (its ServiceProvider, or its own service class).');
        }

        if ($mainClass) {
            $namespacePrefix = 'App\\Modules\\'.Str::studly($slug).'\\';

            if (!str_starts_with($mainClass, $namespacePrefix)) {
                $fail("main_class [{$mainClass}] is not under this plugin's own namespace [{$namespacePrefix}].");
            }

            $relativeClassPath = str_replace('\\', '/', substr($mainClass, strlen($namespacePrefix))).'.php';
            $requiredFileEntry = ($isWrapped ? "{$slug}/" : '')."app/{$relativeClassPath}";

            if ($zip->locateName($requiredFileEntry) === false) {
                $fail("Zip is missing app/{$relativeClassPath} — the file declared as main_class in module.json.");
            }
        }

        $destination = $isWrapped ? app_path('Modules') : app_path('Modules/'.$slug);
        $zip->extractTo($destination);
        $zip->close();
        unlink($filePath);
    }
}
