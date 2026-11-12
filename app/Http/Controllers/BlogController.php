<?php

namespace App\Http\Controllers;

use Exception;
use Throwable;
use Validator;
use Carbon\Carbon;
use function view;
use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\Module;
use function compact;
use App\Models\Category;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\CachingService;
use App\Services\ResponseService;
use Illuminate\Support\Facades\Log;
use App\Services\Module\ModuleService;

use Illuminate\Support\Facades\Auth;
use App\Services\NotificationService;
use App\Services\BootstrapTableService;

class BlogController extends Controller {
    private string $uploadFolder;

    public function __construct() {
        $this->uploadFolder = "blog";
    }

    public function index() {
        ResponseService::noAnyPermissionThenRedirect(['blog-list', 'blog-create', 'blog-delete', 'blog-update']);
        $languages = CachingService::getLanguages()->values();
        // Module filter → Category filter: starts on All modules; picking a module
        // reloads the categories from categories() below.
        $modules = Module::active()->orderBy('sequence')->get();
        $categories = $this->activeCategories(null);
        return view('blog.index',compact('languages', 'categories', 'modules'));
    }

    public function create() {
        ResponseService::noPermissionThenRedirect('blog-create');
        $languages = CachingService::getLanguages()->values();
        $defaultLanguage = CachingService::getDefaultLanguage();
        // Module → Category: starts on the default module; picking another module
        // reloads the categories from categories() below.
        $modules = Module::active()->orderBy('sequence')->get();
        $selectedModuleId = ModuleService::defaultModuleId();
        $categories = $this->activeCategories($selectedModuleId);
        return view('blog.create', compact('categories','languages','defaultLanguage', 'modules', 'selectedModuleId'));
    }

    /**
     * Active blog categories of a module (every module when none is given), for the
     * Category dropdowns of the blog list filter and the create / edit forms.
     */
    public function categories(Request $request) {
        ResponseService::noAnyPermissionThenSendJson(['blog-list', 'blog-create', 'blog-update']);
        $request->validate(['module_id' => 'nullable|integer|exists:modules,id']);

        $moduleId = $request->filled('module_id') ? (int) $request->input('module_id') : null;
        return response()->json(['data' => $this->activeCategories($moduleId)]);
    }

    private function activeCategories(?int $moduleId) {
        return BlogCategory::without('translations')
            ->where('is_active', 1)
            ->forModule($moduleId)
            ->get(['id', 'name'])
            ->map(fn ($category) => ['id' => $category->id, 'name' => $category->name]);
    }

    /**
     * A blog's module comes from its category, so the Module field isn't stored; it only
     * narrows the Category list. Reject a category that isn't in the chosen module.
     *
     * @throws ValidationException
     */
    private function validateCategoryModule(Request $request): void
    {
        if (!$request->filled('module_id') || !$request->filled('category_id')) {
            return;
        }

        $inModule = BlogCategory::forModule((int) $request->input('module_id'))
            ->where('id', $request->input('category_id'))
            ->exists();
        if (!$inModule) {
            throw ValidationException::withMessages([
                'category_id' => __('The selected category does not belong to the chosen module.'),
            ]);
        }
    }

        public function store(Request $request)
        {
            ResponseService::noPermissionThenSendJson('blog-create');
            $request->validate([
                'title' => 'required',
                'category_id' => 'required|integer|exists:blog_categories,id',
                'blog_description' => 'required',
                'tags' => 'required|array|min:1',
                'slug' => 'nullable|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                'image' => 'required|mimes:jpg,jpeg,png|max:7168',
                'module_id' => 'nullable|exists:modules,id',
            ]);
            $this->validateCategoryModule($request);
            HelperService::validateSeoSchemas($request, CachingService::getLanguages()->pluck('id')->all());
            try {
                $data = [
                    'title'       => $request->input('title'),
                    'category_id' => $request->input('category_id'),
                    'slug'        => HelperService::generateUniqueSlug(new Blog(), $request->filled('slug') ? $request->input('slug') : $request->input('title')),
                    'description' => $request->input('blog_description') ?? '',
                    'tags'        => implode(',', $request->input('tags', []) ?? []),
                ];

                if ($request->hasFile('image')) {
                    $data['image'] = FileService::compressAndUpload($request->file('image'), $this->uploadFolder);
                }

                $blog = Blog::create($data);

                $translationData = [];
                foreach ($request->input('translations', []) as $langId => $transData) {
                    $translatedTitle = $transData['title'] ?? '';
                    $translatedDesc  = $transData['description'] ?? '';
                    $translatedTagsArr = $transData['tags'] ?? [];
                    $translatedTags = is_array($translatedTagsArr) ? implode(',', $translatedTagsArr) : (string) $translatedTagsArr;

                    if ($translatedTitle !== '' || $translatedDesc !== '' || $translatedTags !== '') {
                        $translationData[] = [
                            'translatable_id'   => $blog->id,
                            'translatable_type' => Blog::class,
                            'key'               => 'title',
                            'value'             => $translatedTitle,
                            'language_id'       => $langId,
                        ];
                        $translationData[] = [
                            'translatable_id'   => $blog->id,
                            'translatable_type' => Blog::class,
                            'key'               => 'description',
                            'value'             => $translatedDesc,
                            'language_id'       => $langId,
                        ];
                        $translationData[] = [
                            'translatable_id'   => $blog->id,
                            'translatable_type' => Blog::class,
                            'key'               => 'tags',
                            'value'             => $translatedTags,
                            'language_id'       => $langId,
                        ];
                    }
                }
                if (!empty($translationData)) {
                    HelperService::storeTranslations($translationData);
                }

                // Store SEO details
                $languages = CachingService::getLanguages();
                HelperService::storeSeoDetails($blog, $request, $languages->pluck('id')->toArray());

                $customBodyFields = [
                    'image' => $blog->image,
                    'blog_id' => $blog->id,
                    'type' => 'blog'
                ];

                NotificationService::dispatchChunkedNotifications(
                    $blog->title,
                    "New blog uploaded by admin. Check it out!",
                    'blog',
                    $customBodyFields,
                    true, // sendToAll
                    []    // userIds (empty for sendToAll)
                );
                ResponseService::successResponse("Blog Added Successfully", ['redirect_url' => route('blog.index')]);

            } catch (Throwable $th) {
                ResponseService::logErrorResponse($th, "BlogController->store");
                ResponseService::errorResponse();
            }
        }


    public function show(Request $request) {
        ResponseService::noPermissionThenSendJson('blog-list');
        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);
        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'ASC');


        $sql = Blog::with('category:id,name')->withCount([
            'feedbacks as useful_count' => function ($query) {
                $query->where('is_useful', 1);
            },
            'feedbacks as not_useful_count' => function ($query) {
                $query->where('is_useful', 0);
            }
        ]);

        if (!empty($request->search)) {
            $sql = $sql->search($request->search);
        }

        if ($request->filled('module_id')) {
            $sql = $sql->whereIn('category_id', BlogCategory::forModule((int) $request->input('module_id'))->select('id'));
        }

        if ($request->filled('category_id')) {
            $sql = $sql->where('category_id', $request->input('category_id'));
        }

        $total = $sql->count();
        $sql = $sql->sort($sort, $order)->skip($offset)->take($limit);
        $result = $sql->get();
        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = 1;
        foreach ($result as $key => $row) {
            $operate = '';
            if (Auth::user()->can('blog-update')) {
                $operate .= BootstrapTableService::editButton(route('blog.edit', $row->id));
            }

            if (Auth::user()->can('blog-delete')) {
                $operate .= BootstrapTableService::deleteButton(route('blog.destroy', $row->id));
            }
            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['created_at'] = Carbon::createFromFormat('Y-m-d H:i:s', $row->created_at)->format('d-m-y H:i:s');
            $tempRow['updated_at'] = Carbon::createFromFormat('Y-m-d H:i:s', $row->updated_at)->format('d-m-y H:i:s');
            $tempRow['operate'] = $operate;
              $tempRow['description'] = Str::limit(strip_tags($row->description), 200);

            $rows[] = $tempRow;
        }
        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function edit($id)
    {
        ResponseService::noPermissionThenRedirect('blog-update');

        $blog = Blog::with('translations')->findOrFail($id);
        $languages = CachingService::getLanguages()->values();

        // Transform morph translations to column-style objects for blade views
        $translations = HelperService::transformTranslationsForEdit($blog->translations);
        $seoTranslations = HelperService::prepareSeoTranslationsForEdit($blog);
        $defaultLanguage = CachingService::getDefaultLanguage();
        $modules = Module::active()->orderBy('sequence')->get();
        // The blog's module is its category's module.
        $selectedModuleId = BlogCategory::whereKey($blog->category_id)->value('module_id');
        $categories = $this->activeCategories($selectedModuleId);
        return view('blog.edit', compact('blog', 'categories', 'languages', 'defaultLanguage', 'translations', 'seoTranslations', 'modules', 'selectedModuleId'));
    }


    public function update(Request $request, $id)
        {
            ResponseService::noPermissionThenSendJson('blog-update');
            // Before the try: its catch-all would swallow these validation errors.
            $request->validate(['module_id' => 'nullable|exists:modules,id']);
            $this->validateCategoryModule($request);
            HelperService::validateSeoSchemas($request, CachingService::getLanguages()->pluck('id')->all());
            try {
                $request->validate([
                    'title' => 'required',
                    'category_id' => 'required|integer|exists:blog_categories,id',
                    'blog_description' => 'required',
                    'tags' => 'required|array|min:1',
                    'slug' => 'nullable|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                    'image' => 'nullable|mimes:jpg,jpeg,png|max:7168',
                ]);

                $blog = Blog::findOrFail($id);
                $data = [
                    'title'       => $request->input('title'),
                    'category_id' => $request->input('category_id'),
                    'slug'        => HelperService::generateUniqueSlug(new Blog(), $request->filled('slug') ? $request->input('slug') : $request->input('title'), $blog->id),
                    'description' => $request->input('blog_description') ?? '',
                    'tags'        => implode(',', $request->input('tags', []) ?? []),
                ];

                if ($request->hasFile('image')) {
                    $data['image'] = FileService::compressAndReplace($request->file('image'), $this->uploadFolder, $blog->getRawOriginal('image'));
                }

                $blog->update($data);

                $translationData = [];
                foreach ($request->input('translations', []) as $langId => $transData) {
                    $translatedTitle = $transData['title'] ?? '';
                    $translatedDesc  = $transData['description'] ?? '';
                    $translatedTagsArr = $transData['tags'] ?? [];
                    $translatedTags = is_array($translatedTagsArr) ? implode(',', $translatedTagsArr) : (string) $translatedTagsArr;

                    $translationData[] = [
                        'translatable_id'   => $blog->id,
                        'translatable_type' => Blog::class,
                        'key'               => 'title',
                        'value'             => $translatedTitle,
                        'language_id'       => $langId,
                    ];
                    $translationData[] = [
                        'translatable_id'   => $blog->id,
                        'translatable_type' => Blog::class,
                        'key'               => 'description',
                        'value'             => $translatedDesc,
                        'language_id'       => $langId,
                    ];
                    $translationData[] = [
                        'translatable_id'   => $blog->id,
                        'translatable_type' => Blog::class,
                        'key'               => 'tags',
                        'value'             => $translatedTags,
                        'language_id'       => $langId,
                    ];
                }
                if (!empty($translationData)) {
                    HelperService::storeTranslations($translationData, true);
                }

                // Store SEO details
                $languages = CachingService::getLanguages();
                HelperService::storeSeoDetails($blog, $request, $languages->pluck('id')->toArray());

                ResponseService::successResponse("Blog Updated Successfully", ['redirect_url' => route('blog.index')]);
            } catch (Throwable $th) {
                ResponseService::logErrorResponse($th);
                ResponseService::errorResponse('Something Went Wrong');
            }
        }


    public function destroy($id) {
        ResponseService::noPermissionThenSendJson('blog-delete');
        try {
            $blog = Blog::find($id);
            FileService::delete($blog->getRawOriginal('image'));
            $blog->delete();
            ResponseService::successResponse('Blog delete successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse('Something Went Wrong ');
        }
    }

}
