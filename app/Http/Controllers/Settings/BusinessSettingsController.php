<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Tenancy\CurrentBusiness;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BusinessSettingsController extends Controller
{
    public function __construct(private CurrentBusiness $tenancy, private Audit $audit) {}

    public function edit(): Response
    {
        $business = $this->tenancy->businessOrFail();

        return Inertia::render('settings/Business', [
            'business' => [
                'name' => $business->name,
                'timezone' => $business->timezone,
                'closing_time' => substr($business->closing_time, 0, 5),
                'require_two_factor' => $business->require_two_factor,
                'idle_lock_minutes' => (int) ($business->settings['idle_lock_minutes'] ?? 10),
                'daily_summary' => (bool) ($business->settings['daily_summary'] ?? true),
            ],
            'timezones' => DateTimeZone::listIdentifiers(DateTimeZone::AFRICA),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $business = $this->tenancy->businessOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'closing_time' => ['required', 'date_format:H:i'],
            'require_two_factor' => ['required', 'boolean'],
            'idle_lock_minutes' => ['required', 'integer', 'min:2', 'max:120'],
            // Optional: a form that doesn't send it leaves the choice as it was.
            'daily_summary' => ['sometimes', 'boolean'],
        ]);

        $business->fill([
            'name' => $validated['name'],
            'timezone' => $validated['timezone'],
            'closing_time' => $validated['closing_time'].':00',
            'require_two_factor' => $validated['require_two_factor'],
            'settings' => [
                ...$business->settings,
                'idle_lock_minutes' => (int) $validated['idle_lock_minutes'],
                'daily_summary' => $validated['daily_summary'] ?? ($business->settings['daily_summary'] ?? true),
            ],
        ]);

        $this->audit->recordChanges('business.settings_updated', $business);
        $business->save();

        $this->toast('Business settings saved.');

        return back();
    }
}
