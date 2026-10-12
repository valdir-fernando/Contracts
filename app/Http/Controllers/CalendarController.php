<?php

namespace App\Http\Controllers;

use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\User;
use App\Services\ExpirationCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CalendarController extends Controller
{
    public function index(Request $request, ExpirationCalendar $calendar)
    {
        Gate::authorize('viewAny', Contract::class);
        $data = $request->validate(['month_number' => 'nullable|integer|min:1|max:12|required_with:calendar_year', 'calendar_year' => 'nullable|integer|min:1900|max:2199|required_with:month_number', 'month' => ['nullable', 'date_format:Y-m', 'regex:/^(19|20|21)[0-9]{2}-(0[1-9]|1[0-2])$/'], 'page' => 'nullable|integer|min:1']);
        $selected = isset($data['month_number'], $data['calendar_year']) ? sprintf('%04d-%02d', $data['calendar_year'], $data['month_number']) : ($data['month'] ?? today()->format('Y-m'));
        $month = CarbonImmutable::createFromFormat('!Y-m', $selected);
        $query = $calendar->query($request->user(), $month, $month->endOfMonth());
        $counts = (clone $query)->reorder()->selectRaw('DATE(COALESCE(vigencia_fim_atual, vigencia_fim_original)) AS end_day, COUNT(*) AS total')
            ->groupByRaw('DATE(COALESCE(vigencia_fim_atual, vigencia_fim_original))')->pluck('total', 'end_day');

        return view('calendar.index', ['month' => $month, 'counts' => $counts,
            'contracts' => $query->paginate(30)->withQueryString(),
            'user' => $request->user(),
            'reviewCount' => Contract::visibleTo($request->user())->withValidity('em_revisao')->count(),
            'unknownCount' => Contract::visibleTo($request->user())->withValidity('indeterminado')->count(),
        ]);
    }

    public function preferences(Request $request)
    {
        $data = $request->validate(['enabled' => 'required|boolean', 'days' => 'required|integer|min:1|max:365']);
        DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active && (int) $request->session()->get('auth_version', 0) === $user->session_version, 403);
            $user->expiration_alert_enabled = (bool) $data['enabled'];
            $user->expiration_alert_days = $data['days'];
            $user->save();
            ActivityEvent::create(['actor_id' => $user->id, 'action' => 'expiration_preferences_updated',
                'metadata' => ['enabled' => (bool) $data['enabled'], 'days' => (int) $data['days']], 'created_at' => now()]);
        });

        return redirect()->route('calendar.index')->with('status', 'Preferências de alerta atualizadas.');
    }
}
