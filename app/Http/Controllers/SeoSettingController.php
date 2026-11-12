<?php

namespace App\Http\Controllers;
use App\Models\SeoSetting;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class SeoSettingController extends Controller
{
    private string $uploadFolder;

    public function __construct() {
        $this->uploadFolder = "seo-setting";
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        ResponseService::noPermissionThenRedirect('settings-update');
        $languages = CachingService::getLanguages()->values();
        return view('settings.seo-setting-create', compact('languages'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'page'          => ['required', Rule::in(array_keys(SeoSetting::PAGES)), 'unique:seo_settings,page'],
                'title.1'       => 'required|string',
                'description.1' => 'required|string',
                'keywords.1'    => 'nullable|string',
                'schema.1'      => 'nullable|string',
                'image'         => 'nullable|mimes:jpeg,png,jpg,svg|max:7168',
                'languages'     => 'required|array',
                'languages.*'   => 'exists:languages,id',
            ],
            [
                'page.unique'              => 'This page already has SEO settings.',
                'title.1.required'         => 'The English title field is required.',
                'description.1.required'   => 'The English description field is required.',
            ]
        );

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        $languageIds = $request->input('languages', []);
        if ($error = HelperService::seoSchemaError($request, $languageIds)) {
            return ResponseService::validationError($error);
        }

        // Everything is valid: nothing below may leave a half-saved record behind.
        $image = null;
        try {
            DB::beginTransaction();

            $image = $request->hasFile('image') ? FileService::upload($request->file('image'), $this->uploadFolder) : null;

            // Main SEO setting (language_id = 1)
            $seoSetting = SeoSetting::create([
                'page'        => $request->input('page'),
                'title'       => $request->input('title.1'),
                'description' => $request->input('description.1'),
                'keywords'    => $request->input('keywords.1'),
                'schema'      => $request->input('schema.1'),
                'image'       => $image,
            ]);

            $translationData = $this->translationRows($seoSetting, $request, $languageIds, true);
            if (!empty($translationData)) {
                HelperService::storeTranslations($translationData);
            }

            DB::commit();
        } catch (Throwable $th) {
            DB::rollBack();
            FileService::delete($image);
            ResponseService::logErrorResponse($th, "SeoSetting Controller -> Store");
            return ResponseService::errorResponse('Something Went Wrong');
        }

        return ResponseService::successResponse('SEO Setting Successfully Added');
    }


    /**
     * Display the specified resource.
     */
    public function show(Request $request)
    {
        $offset = $request->offset ?? 0;
        $limit = $request->limit ?? 10;
        $sort = $request->sort ?? 'id';
        $order = $request->order ?? 'DESC';

        $sql = SeoSetting::with('translations')->orderBy($sort, $order);

        if (!empty($_GET['search'])) {
            $search = $_GET['search'];
            $sql->whereLike('id', "%$search%")->orWhereLike('code', "%$search%")->orWhereLike('name', "%$search%");
        }
        $total = $sql->count();
        $sql->skip($offset)->take($limit);
        $result = $sql->get();
        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        foreach ($result as $key => $row) {
            $tempRow = $row->toArray();
            $operate = '';
            if ($row->code != "en") {
                $operate .= BootstrapTableService::editButton(route('seo-setting.edit', $row->id));
                $operate .= BootstrapTableService::deleteButton(route('seo-setting.destroy', $row->id));
            }
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        ResponseService::noPermissionThenRedirect('settings-update');
        $seoSetting = SeoSetting::with('translations')->findOrFail($id);

        $translations = [];
        $translations[1] = [
            'title'       => $seoSetting->title,
            'description' => $seoSetting->description,
            'keywords'    => $seoSetting->keywords,
            'schema'      => $seoSetting->schema,
        ];
        foreach ($seoSetting->translations->groupBy('language_id') as $langId => $items) {
            foreach ($items as $item) {
                $translations[$langId][$item->key] = $item->value;
            }
        }

        $languages = CachingService::getLanguages()->values();
        return view('settings.seo-setting-edit', compact('seoSetting', 'translations', 'languages'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'title.1'       => 'required|string',
                'description.1' => 'required|string',
                'image'         => 'nullable|mimes:jpeg,png,jpg,svg|max:7168',
                'remove_image'  => 'nullable|boolean',
            ],
            [
                'title.1.required'         => 'The English title field is required.',
                'description.1.required'   => 'The English description field is required.',
            ]
        );

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        $languageIds = $request->input('languages', []);
        if ($error = HelperService::seoSchemaError($request, $languageIds)) {
            return ResponseService::validationError($error);
        }

        // Everything is valid: save it all or nothing. The old image file is only deleted
        // once the change is saved, and a new upload is removed again on failure. A new
        // image replaces the old one; "Remove current image" clears it when none is chosen.
        $newImage = null;
        $removeImage = false;
        try {
            $seo = SeoSetting::findOrFail($id);
            $oldImage = $seo->getRawOriginal('image');

            DB::beginTransaction();

            // The page can't be changed after creation (the edit form shows it read-only).
            $data = [
                'title'       => $request->input('title.1'),
                'description' => $request->input('description.1'),
                'keywords'    => $request->input('keywords.1'),
                'schema'      => $request->input('schema.1'),
            ];
            if ($request->hasFile('image')) {
                $newImage = FileService::upload($request->file('image'), $this->uploadFolder);
                $data['image'] = $newImage;
            } elseif ($request->boolean('remove_image') && $oldImage) {
                $removeImage = true;
                $data['image'] = null;
            }
            $seo->update($data);

            $translationData = $this->translationRows($seo, $request, $languageIds, false);
            if (!empty($translationData)) {
                HelperService::storeTranslations($translationData, true);
            }

            DB::commit();
        } catch (Throwable $th) {
            DB::rollBack();
            FileService::delete($newImage);
            ResponseService::logErrorResponse($th, "SeoSetting Controller -> Update");
            return ResponseService::errorResponse('Something Went Wrong');
        }

        if (($newImage || $removeImage) && $oldImage) {
            FileService::delete($oldImage);
        }

        return ResponseService::successResponse('SEO Setting Updated Successfully');
    }

    /**
     * Translation rows for every language except the default one (language_id = 1).
     * On create, a language with nothing filled in is skipped.
     */
    private function translationRows(SeoSetting $seo, Request $request, array $languageIds, bool $skipEmpty): array
    {
        $rows = [];
        foreach ($languageIds as $langId) {
            if ($langId == 1) {
                continue;
            }

            $values = [];
            foreach (['title', 'description', 'keywords', 'schema'] as $key) {
                $values[$key] = $request->input("$key.$langId");
            }
            if ($skipEmpty && empty(array_filter($values))) {
                continue;
            }

            foreach ($values as $key => $value) {
                $rows[] = [
                    'translatable_id'   => $seo->id,
                    'translatable_type' => SeoSetting::class,
                    'key'               => $key,
                    'value'             => $value ?? '',
                    'language_id'       => $langId,
                ];
            }
        }

        return $rows;
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
                $seo_setting = SeoSetting::findOrFail($id);
                $seo_setting->delete();
                FileService::delete($seo_setting->getRawOriginal('image'));
                ResponseService::successResponse('Seo Setting Deleted successfully');
            } catch (Throwable $th) {
                ResponseService::logErrorRedirect($th, "Language Controller --> Destroy");
                ResponseService::errorResponse('Something Went Wrong');
            }
    }
}
