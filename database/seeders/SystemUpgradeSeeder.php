<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\HomeScreenSection;
use App\Models\Module;
use App\Models\Setting;
use App\Services\DefaultSettingService;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use App\Services\Module\ModuleService;

class SystemUpgradeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // * means create,list,update,delete
        $permissionsList = [
            'role'                        => '*',
            'staff'                       => '*',
            // Install-only: no module-create (there is no create route —
            // modules arrive from the core migration or a plugin install)
            // and no module-delete yet (nothing is deletable until the
            // first addon module's uninstall flow ships).
            'module'                      => [
                'only' => ['list', 'update']
            ],
            'category'                    => '*',
            'custom-field'                => '*',
            'chat-template'               => '*',
            'advertisement'               => '*',
            'advertisement-listing-package'        => '*',
            'featured-advertisement-package'       => '*',
            'user-package'                => [
                'only' => ['list']
            ],
            'payment-transactions'        => [
                'only' => ['list']
            ],
            'slider'                      => [
                // update: status toggle in the slider list.
                'only' => ['create', 'update', 'delete', 'list']
            ],
            'feature-section'             => '*',
            'report-reason'               => '*',
            'user-reports'                => [
                'only' => ['list']
            ],
            'notification'                => '*',
            'customer'                    => [
                'only' => ['list', 'update']
            ],
            'settings'                    => [
                'only' => ['update']
            ],
            'admin-chat'                  => [
                'only' => ['manage']
            ],
            'tip'                         => '*',
            'blog'                        => '*',
            'currency'                    => '*',
            'country'                     => '*',
            'state'                       => '*',
            'city'                        => '*',
            'area'                        => '*',
            'faq'                         => '*',
            'seller-verification-field'   => '*',
            'seller-verification-request' => [
                'only' => ['list', 'update']
            ],
            'seller-review' => '*',
            'user-queries' => [
                'only' => ['list']
            ],
            'home-screen-section' => [
                'only' => ['list', 'update']
            ],
            'banner-ad'                   => '*',
        ];

        $permissionsList = self::generatePermissionList($permissionsList);

        $permissions = array_map(static function ($data) {
            return [
                'name'       => $data,
                'guard_name' => 'web'
            ];
        }, $permissionsList);
        // permissions is unique on (name, guard_name); pgsql's ON CONFLICT needs the exact unique columns
        Permission::upsert($permissions, ['name', 'guard_name'], ['name']);

        //        Role::updateOrCreate(['name' => 'Super Admin']);
        //        $superAdminHasAccessTo = [
        //            'role-list',
        //            'role-create',
        //            'role-update',
        //            'role-delete',
        //        ];
        //        $role->syncPermissions($superAdminHasAccessTo);
        /*Create Settings which are new & ignore the old values*/
        Setting::insertOrIgnore(DefaultSettingService::get());

        /*Add new module setting keys & refresh existing definitions; module values are never touched*/
        ModuleService::syncSettingDefinitions();

        /*Give every module its home screen section rows; existing rows are never touched*/
        foreach (Module::pluck('id') as $moduleId) {
            HomeScreenSection::ensureForModule($moduleId);
        }
    }

    /**
     * @param array {
     * <pre>
     *  permission_name :array<string> array {
     *      * : string // List , Create , Edit , Delete
     *      only : string // List , Create , Edit , Delete
     *      custom: array { // custom permissions will be prefixed with permission_name eg. permission_name-permission1
     *          permission1: string,
     *          permission2: string,
     *      }
     *  }
     * } $permission
     * @return array
     */
    public static function generatePermissionList($permissions)
    {
        $permissionList = [];
        foreach ($permissions as $name => $permission) {
            $defaultPermission = [
                $name . "-list",
                $name . "-create",
                $name . "-update",
                $name . "-delete"
            ];
            if (is_array($permission)) {
                // * OR only param either is required
                if (in_array("*", $permission, true)) {
                    $permissionList = array_merge($permissionList ?? [], $defaultPermission);
                } else if (array_key_exists("only", $permission)) {
                    foreach ($permission["only"] as $row) {
                        $permissionList[] = $name . "-" . strtolower($row);
                    }
                }

                if (array_key_exists("custom", $permission)) {
                    foreach ($permission["custom"] as $customPermission) {
                        $permissionList[] = $name . "-" . $customPermission;
                    }
                }
            } else {
                $permissionList = array_merge($permissionList ?? [], $defaultPermission);
            }
        }
        return $permissionList;
    }
}
