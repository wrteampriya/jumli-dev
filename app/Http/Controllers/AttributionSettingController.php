<?php

namespace App\Http\Controllers;

use App\Models\AttributionEvent;
use App\Models\AttributionEventLog;
use App\Models\AttributionProvider;
use App\Models\AttributionProviderEvent;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class AttributionSettingController extends Controller
{
    public function index()
    {
        ResponseService::noPermissionThenRedirect('attribution-settings-update');

        $providers = AttributionProvider::with('settings')->get()->keyBy('key');

        return view('settings.attribution-analytics', compact('providers'));
    }

    public function store(Request $request)
    {
        ResponseService::noPermissionThenSendJson('attribution-settings-update');

        $validator = Validator::make($request->all(), [
            'provider' => 'required|array',
            'provider.meta.status' => 'required|boolean',
            'provider.firebase.status' => 'required|boolean',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            foreach ($request->input('provider') as $key => $data) {
                AttributionProvider::where('key', $key)->update([
                    'status' => $data['status'],
                ]);
            }

            ResponseService::successResponse('Settings Updated Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function eventsIndex()
    {
        ResponseService::noPermissionThenRedirect('attribution-settings-update');

        $providers = AttributionProvider::orderBy('id')->get();
        $events = AttributionEvent::orderBy('id')->get();
        $providerEvents = AttributionProviderEvent::all()
            ->groupBy('provider_id')
            ->map(fn ($group) => $group->keyBy('event_id'));

        return view('settings.attribution-events', compact('providers', 'events', 'providerEvents'));
    }

    public function eventsStore(Request $request)
    {
        ResponseService::noPermissionThenSendJson('attribution-settings-update');

        $validator = Validator::make($request->all(), [
            'events' => 'required|array',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            $allEvents = AttributionEvent::all()->keyBy('id');

            foreach ($request->input('events') as $providerId => $eventsData) {
                if (! is_array($eventsData)) {
                    continue;
                }
                foreach ($eventsData as $eventId => $data) {
                    $event = $allEvents->get($eventId);
                    if (! $event) {
                        continue;
                    }

                    if ($event->is_configurable) {
                        AttributionProviderEvent::updateOrCreate(
                            [
                                'provider_id' => $providerId,
                                'event_id' => $eventId,
                            ],
                            [
                                'enable' => (bool) ($data['enable'] ?? false),
                                'log' => (bool) ($data['log'] ?? false),
                            ]
                        );
                    } else {
                        // Non-configurable events (e.g. app_open) are always enabled
                        AttributionProviderEvent::updateOrCreate(
                            [
                                'provider_id' => $providerId,
                                'event_id' => $eventId,
                            ],
                            [
                                'enable' => true,
                                'log' => (bool) ($data['log'] ?? false),
                            ]
                        );
                    }
                }
            }

            ResponseService::successResponse('Settings Updated Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function logsIndex()
    {
        ResponseService::noPermissionThenRedirect('attribution-settings-update');

        return view('settings.attribution-event-logs');
    }

    public function logsShow(Request $request)
    {
        ResponseService::noPermissionThenSendJson('attribution-settings-update');

        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);
        $sort = $request->input('sort', 'created_at');
        $order = $request->input('order', 'DESC');

        $sql = AttributionEventLog::with(['params', 'user:id,name'])->orderBy($sort, $order);
        if ($sort === 'created_at') {
            $sql->orderBy('id', $order);
        }

        if (! empty($request->search)) {
            $search = $request->search;
            $sql->where(function ($query) use ($search) {
                $query->where('event_key', 'LIKE', "%$search%")
                    ->orWhere('provider', 'LIKE', "%$search%")
                    ->orWhere('status', 'LIKE', "%$search%")
                    ->orWhere('message', 'LIKE', "%$search%");
            });
        }

        $total = $sql->count();
        $sql->skip($offset)->take($limit);
        $result = $sql->get();

        $rows = [];
        foreach ($result as $row) {
            $rows[] = [
                'id' => $row->id,
                'event_key' => $row->event_key,
                'provider' => $row->provider ?? '-',
                'status_badge' => $row->status === 'error'
                    ? '<span class="badge bg-danger">' . __('error') . '</span>'
                    : '<span class="badge bg-success">' . __('success') . '</span>',
                'params_display' => $row->params->isNotEmpty()
                    ? $row->params->map(fn ($p) => "{$p->key}: {$p->value}")->implode(', ')
                    : '-',
                'message' => $row->message ?? '-',
                'user_name' => $row->user->name ?? '-',
                'created_at' => $row->created_at->format('Y-m-d H:i:s'),
            ];
        }

        return response()->json(['total' => $total, 'rows' => $rows]);
    }
}
