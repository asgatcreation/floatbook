<?php

namespace App\Http\Controllers;

use App\Models\Outlet;
use App\Models\OutletWallet;
use App\Models\Provider;
use App\Models\Terminal;
use App\Support\Audit;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OutletController extends Controller
{
    public function __construct(private CurrentBusiness $tenancy, private Audit $audit) {}

    public function index(): Response
    {
        $outlets = Outlet::query()
            ->withCount(['memberships as staff_count' => fn ($query) => $query->where('status', 'active')])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'address', 'is_active']);

        $terminals = Terminal::query()->with(['provider:id,name,color', 'wallet.provider:id,name'])
            ->withCount('members')->orderBy('name')->get()->groupBy('outlet_id');
        $wallets = OutletWallet::query()->with(['provider:id,name', 'terminals:id,outlet_wallet_id,name'])->get()->groupBy('outlet_id');

        return Inertia::render('outlets/Index', [
            'outlets' => $outlets->map(fn (Outlet $outlet) => [
                ...$outlet->only(['id', 'name', 'code', 'address', 'is_active', 'staff_count']),
                'terminals' => ($terminals[$outlet->id] ?? collect())->map(fn (Terminal $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'terminal_code' => $t->terminal_code,
                    'serial_number' => $t->serial_number,
                    'is_active' => $t->is_active,
                    'provider' => ['id' => $t->provider_id, 'name' => $t->provider->name, 'color' => $t->provider->color],
                    'wallet' => $t->wallet->label(),
                    'staff_count' => $t->members_count,
                ])->values(),
                'wallets' => ($wallets[$outlet->id] ?? collect())->map(fn (OutletWallet $w) => [
                    'id' => $w->id, 'provider_id' => $w->provider_id, 'label' => $w->label(),
                    'terminals' => $w->terminals->pluck('name')->join(' and '),
                ])->values(),
            ]),
            'providers' => Provider::query()->active()->get(['id', 'name', 'color']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $validated['code'] = $validated['code'] ?? $this->suggestCode($validated['name']);

        $outlet = Outlet::create($validated);
        $this->audit->record('outlet.created', $outlet, after: $outlet->only(['name', 'code', 'address']));

        $this->toast("{$outlet->name} added.");

        return back();
    }

    public function update(Request $request, Outlet $outlet): RedirectResponse
    {
        $outlet->fill($this->validated($request, $outlet));
        $this->audit->recordChanges('outlet.updated', $outlet);
        $outlet->save();

        $this->toast("{$outlet->name} updated.");

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Outlet $outlet = null): array
    {
        $businessId = $this->tenancy->id();

        // Codes are stored in capitals, so compare in capitals too.
        if ($request->filled('code')) {
            $request->merge(['code' => Str::upper(trim($request->string('code')->value()))]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:60',
                Rule::unique('outlets')->where('business_id', $businessId)->ignore($outlet?->id)],
            'code' => ['nullable', 'string', 'max:12', 'alpha_dash:ascii',
                Rule::unique('outlets')->where('business_id', $businessId)->ignore($outlet?->id)],
            'address' => ['nullable', 'string', 'max:160'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $validated;
    }

    private function suggestCode(string $name): string
    {
        // "Ketu Main" -> KETU, "Mile 12 Market" -> MILE12: the first word, plus the next one if it's a number.
        $words = preg_split('/[^A-Za-z0-9]+/', Str::ascii($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['OUT'];
        $first = isset($words[1]) && ctype_digit($words[1]) ? $words[0].$words[1] : $words[0];
        $base = Str::upper(Str::substr($first, 0, 6));
        $base = strlen($base) >= 2 ? $base : 'OUT';
        $code = $base;
        $n = 2;

        while (Outlet::query()->where('code', $code)->exists()) {
            $code = Str::substr($base, 0, 6).$n++;
        }

        return $code;
    }
}
