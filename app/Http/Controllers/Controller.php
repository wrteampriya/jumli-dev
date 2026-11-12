<?php

namespace App\Http\Controllers;

use App\Support\DbCompat;
use App\Models\ContactUs;
use App\Models\Module;
use App\Services\CachingService;
use App\Services\HelperService;
use App\Services\NotificationService;
use App\Services\ResponseService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Session;
use Throwable;

/*Create Method which are common across the system*/

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    public function changeRowOrder(Request $request)
    {
        try {
            $request->validate([
                'data'   => 'required|array',
                'table'  => 'required|string',
                'column' => 'nullable',
            ]);
            $column = $request->column ?? "sequence";

            $data = [];
            foreach ($request->data as $index => $row) {
                $data[$row['id']] = $index;
            }
            DbCompat::updateIntColumnById((string)$request->table, (string)$column, $data);
            ResponseService::successResponse("Order Changed Successfully");
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function changeStatus(Request $request)
    {
        try {
            $request->validate([
                'id'     => 'required|numeric',
                'status' => 'required|boolean',
                'table'  => 'required|string',
                'column' => 'nullable',
            ]);
            switch($request->table){
                case 'users':
                    ResponseService::noPermissionThenSendJson('customer-update');
                    break;
                case 'items':
                    ResponseService::noPermissionThenSendJson('item-update');
                    break;
                case 'categories':
                    ResponseService::noPermissionThenSendJson('category-update');
                    break;
                case 'packages':
                    ResponseService::noAnyPermissionThenSendJson(['advertisement-listing-package-update', 'featured-advertisement-package-update']);
                    break;
                case 'staffs':
                    ResponseService::noPermissionThenSendJson('staff-update');
                    break;
                case 'tips':
                    ResponseService::noPermissionThenSendJson('tip-update');
                    break;
                case 'chat_templates':
                    ResponseService::noPermissionThenSendJson('chat-template-update');
                    break;
                case 'countries':
                    ResponseService::noPermissionThenSendJson('country-update');
                    break;
                case 'states':
                    ResponseService::noPermissionThenSendJson('state-update');
                    break;
                case 'cities':
                    ResponseService::noPermissionThenSendJson('city-update');
                    break;
                case 'areas':
                    ResponseService::noPermissionThenSendJson('area-update');
                    break;
                case 'modules':
                    ResponseService::noPermissionThenSendJson('module-update');
                    break;
                case 'sliders':
                    ResponseService::noPermissionThenSendJson('slider-update');
                    break;
                case 'feature_sections':
                    ResponseService::noPermissionThenSendJson('feature-section-update');
                    break;
                case 'popular_categories':
                case 'home_screen_sections':
                    ResponseService::noPermissionThenSendJson('home-screen-section-update');
                    break;
                case 'banner_ads':
                    ResponseService::noPermissionThenSendJson('banner-ad-update');
                    break;
            }

            // This method writes via a raw DB::table()->update(), which bypasses
            // Eloquent entirely — Module::booted()'s last-active guard never sees
            // this call, so the veto has to be duplicated here (same pattern as
            // the languages veto right below).
            if ($request->table === 'modules' && ! $request->status) {
                $module = Module::find($request->id);

                if ($module && $module->isLastActive()) {
                    return ResponseService::errorResponse(__('At least one module must stay active'));
                }
            }

            if ($request->table === 'languages' && ! $request->status) {
                $language = DB::table('languages')->where('id', $request->id)->first();
                $defaultCode = DB::table('settings')->where('name', 'default_language')->value('value');

                if ($language && ($language->code === 'en' || $language->code === $defaultCode)) {
                    return ResponseService::errorResponse('Cannot disable the default language');
                }
            }

            $column = $request->column ?? "status";

            // Toggles send 1/0; normalise anything else ('', null, 'on') so a NOT NULL boolean never receives ''
            $request->merge(['status' => (int) $request->boolean('status')]);

            //Special case for deleted_at column
            if ($column == "deleted_at") {
                //If status is active then deleted_At will be empty otherwise it will have the current time
                $request->status = ($request->status) ? null : now();
            }
            DB::table($request->table)->where('id', $request->id)->update([(string)$column => $request->status]);

            // Dual banner ads are one logical banner split over rows sharing a group_id — keep the pair in sync.
            if ($request->table === 'banner_ads') {
                $groupId = DB::table('banner_ads')->where('id', $request->id)->value('group_id');
                if ($groupId) {
                    DB::table('banner_ads')->where('group_id', $groupId)->update([(string)$column => $request->status]);
                }
            }

            if ($request->table === 'languages' || $request->table === 'modules') {
                HelperService::revalidateSiteConfig();
            }

            if ($request->table === 'languages') {
                CachingService::removeCache(config('constants.CACHE.LANGUAGE'));
            }

            if ($request->table === 'categories') {
                $category = DB::table('categories')->where('id', $request->id)->first();

                if (!$category) {
                    return ResponseService::errorResponse("Category not found");
                }

                // If trying to activate a category but its parent is inactive
                if ($request->status && $category->parent_category_id) {
                    $parent = DB::table('categories')->where('id', $category->parent_category_id)->first();
                    if ($parent && !$parent->status) {
                        return ResponseService::errorResponse("Cannot activate subcategory while parent is inactive");
                    }
                }

                // Update the category itself
                DB::table('categories')->where('id', $request->id)->update([$column => $request->status]);

                // If status = 0, recursively deactivate all subcategories
                if (!$request->status) {
                    $this->deactivateSubcategories($request->id, $column);
                }


                return ResponseService::successResponse("status updated successfully");
            }

            if ($request->table === 'items') {
                $item = DB::table('items')->where('id', $request->id)->first();
                if ($item) {
                    $user = DB::table('users')->where('id', $item->user_id)->first();
                    if ($user) {
                        // Dispatch chunked notification jobs using centralized service
                        $result = NotificationService::dispatchChunkedNotifications(
                            'About ' . $item->name,
                            "Your Advertisement is " . (is_null($request->status) ? 'Active' : 'Inactive') . " by Admin",
                            'item-update',
                            ['id' => $request->id],
                            false,
                            array($user->id)
                        );
                        // NotificationService::sendFcmNotification(
                        //     $userToken,
                        //     'About ' . $item->name,
                        //     "Your Advertisement is " . (is_null($request->status) ? 'Active' : 'Inactive') . " by Admin",
                        //     'item-update',
                        //     ['id' => $request->id]
                        //);
                    }
                }
            }
            ResponseService::successResponse("Status Updated Successfully");
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    //     public function readLanguageFile() {
    //         try {
    //             //    https://medium.com/@serhii.matrunchyk/using-laravel-localization-with-javascript-and-vuejs-23064d0c210e
    //             header('Content-Type: text/javascript');
    // //        $labels = Cache::remember('lang.js', 3600, static function () {
    // //            $lang = app()->getLocale();
    //             $lang = Session::get('language');
    // //            $lang = app()->getLocale();
    //             $test = $lang->code ?? "en";
    //             $files = resource_path('lang/' . $test . '.json');
    // //            return File::get($files);
    // //        });]
    //             echo('window.languageLabels = ' . File::get($files));
    //             http_response_code(200);
    //             exit();
    //         } catch (Throwable $th) {
    //             ResponseService::errorResponse($th);
    //         }
    //     }

    public function readLanguageFile()
    {
        try {
            header('Content-Type: text/javascript');

            $lang = Session::get('language');
            $code = $lang->code ?? 'en';

            $file = resource_path("lang/{$code}.json");

            if (!file_exists($file)) {
                echo 'window.languageLabels = {};';
                exit;
            }

            $json = File::get($file);

            // Validate JSON
            json_decode($json);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo 'window.languageLabels = {};';
                exit;
            }

            echo "window.languageLabels = {$json};";
            exit;
        } catch (Throwable $th) {
            echo 'window.languageLabels = {};';
            exit;
        }
    }

    public function contactUsUIndex()
    {
        ResponseService::noPermissionThenSendJson('user-queries-list');
        return view('contact-us');
    }

    public function contactUsShow(Request $request)
    {
        ResponseService::noPermissionThenSendJson('user-queries-list');
        $offset = $request->offset ?? 0;
        $limit = $request->limit ?? 10;
        $sort = $request->input('sort', 'sequence');
        $order = $request->order ?? 'DESC';

        $sql = ContactUs::orderBy($sort, $order);

        if ($sort !== 'created_at') {
            $sql->orderBy('created_at', 'desc');
        }

        if (!empty($_GET['search'])) {
            $search = $_GET['search'];
            $sql->whereLike('id', "%$search%")
                ->orWhereLike('name', "%$search%")
                ->orWhereLike('subject', "%$search%")
                ->orWhereLike('message', "%$search%");
        }
        $total = $sql->count();
        $sql->skip($offset)->take($limit);
        $result = $sql->get();
        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        foreach ($result as $row) {
            $rows[] = HelperService::maskDemoUserData($row->toArray());
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }
    private function deactivateSubcategories($parentId, $column = 'status')
    {
        $subcategories = DB::table('categories')->where('parent_category_id', $parentId)->get();

        foreach ($subcategories as $sub) {
            DB::table('categories')->where('id', $sub->id)->update([$column => 0]);
            $this->deactivateSubcategories($sub->id, $column); // recursive call
        }
    }
}
