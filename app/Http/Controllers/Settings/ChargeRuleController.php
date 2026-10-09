<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ChargeKind;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Models\ChargeRule;
use App\Models\ChargeRuleTier;
use App\Models\Outlet;
use App\Models\Provider;
use App\Support\Audit;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ChargeRuleController extends Controller
{
    public function __construct(private CurrentBusiness $tenancy, private Audit $audit) {}

    public function index(): Response
    {
        return Inertia::render('settings/Charges', [
            'rules' => ChargeRule::query()->with(['tiers', 'provider:id,name,color', 'outlet:id,name'])->get()
                ->sortBy(fn (ChargeRule $r) => sprintf('%02d-%d-%d',
                    array_search($r->transaction_type, TransactionType::cases(), true),
                    array_search($r->kind, ChargeKind::cases(), true), $r->specificity()))
                ->map(fn (ChargeRule $r) => [
                    'id' => $r->id,
                    'transaction_type' => $r->transaction_type->value,
                    'kind' => $r->kind->value,
                    'kind_label' => $r->kind->label(),
                    'provider' => $r->provider?->only(['id', 'name', 'color']),
                    'outlet' => $r->outlet?->only(['id', 'name']),
                    'collected_via' => $r->collected_via,
                    'is_active' => $r->is_active,
                    'tiers' => $r->tiers->map(fn (ChargeRuleTier $t) => $t->only(['min_amount', 'max_amount', 'flat', 'percent_bps', 'cap', 'step']))->values(),
                ])->values(),
            'types' => collect(TransactionType::cases())->map(fn (TransactionType $t) => [
                'value' => $t->value, 'label' => $t->label(), 'flow' => $t->flow(),
            ]),
            'kinds' => collect(ChargeKind::cases())->map(fn (ChargeKind $k) => ['value' => $k->value, 'label' => $k->label()]),
            'providers' => Provider::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'color', 'is_active']),
            'outlets' => Outlet::query()->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateRule($request, creating: true);

        $exists = ChargeRule::query()
            ->where('transaction_type', $validated['transaction_type'])
            ->where('kind', $validated['kind'])
            ->where('provider_id', $validated['provider_id'] ?? null)
            ->where('outlet_id', $validated['outlet_id'] ?? null)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['provider_id' => 'A rule for this exact combination already exists. Edit that one instead.']);
        }

        DB::transaction(function () use ($validated): void {
            $rule = ChargeRule::query()->create([
                'transaction_type' => $validated['transaction_type'],
                'kind' => $validated['kind'],
                'provider_id' => $validated['provider_id'] ?? null,
                'outlet_id' => $validated['outlet_id'] ?? null,
                'collected_via' => $validated['collected_via'] ?? 'cash',
            ]);
            $rule->tiers()->createMany($validated['tiers']);
            $this->audit->record('charge_rule.created', $rule, after: ['tiers' => $validated['tiers']]);
        });

        $this->toast('Rule added. New transactions will use it straight away.');

        return back();
    }

    public function update(Request $request, ChargeRule $rule): RedirectResponse
    {
        $validated = $this->validateRule($request, creating: false);

        DB::transaction(function () use ($rule, $validated): void {
            $before = ['tiers' => $rule->tiers->map->only(['min_amount', 'max_amount', 'flat', 'percent_bps', 'cap', 'step'])->all(),
                'collected_via' => $rule->collected_via, 'is_active' => $rule->is_active];

            $rule->forceFill([
                'collected_via' => $validated['collected_via'] ?? $rule->collected_via,
                'is_active' => $validated['is_active'] ?? $rule->is_active,
            ])->save();

            // Tiers are replaced as a set. Past transactions keep the amounts they were saved with.
            $rule->tiers()->delete();
            $rule->tiers()->createMany($validated['tiers']);

            $this->audit->record('charge_rule.updated', $rule, $before,
                ['tiers' => $validated['tiers'], 'collected_via' => $rule->collected_via, 'is_active' => $rule->is_active]);
        });

        $this->toast('Rule saved. It applies to new transactions only.');

        return back();
    }

    public function destroy(ChargeRule $rule): RedirectResponse
    {
        abort_if($rule->provider_id === null && $rule->outlet_id === null, 422, 'The default rule for a type can be switched off but not removed.');

        $this->audit->record('charge_rule.deleted', $rule, ['transaction_type' => $rule->transaction_type->value, 'kind' => $rule->kind->value]);
        $rule->delete();
        $this->toast('Override removed.');

        return back();
    }

    public function storeProvider(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:40'],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $slug = Str::slug($validated['name']);

        if (Provider::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['name' => 'You already have this provider.']);
        }

        $provider = Provider::query()->create([...$validated, 'slug' => $slug, 'sort_order' => Provider::query()->max('sort_order') + 1]);
        $this->audit->record('provider.created', $provider, after: $validated);
        $this->toast("{$provider->name} added. Every outlet now has a {$provider->name} wallet.");

        return back();
    }

    public function updateProvider(Request $request, Provider $provider): RedirectResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $provider->fill($validated);
        $this->audit->recordChanges('provider.updated', $provider);
        $provider->save();
        $this->toast($provider->is_active ? "{$provider->name} switched on." : "{$provider->name} switched off. Its history is kept.");

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRule(Request $request, bool $creating): array
    {
        $businessId = $this->tenancy->id();

        $validated = $request->validate([
            ...($creating ? [
                'transaction_type' => ['required', Rule::enum(TransactionType::class)],
                'kind' => ['required', Rule::enum(ChargeKind::class)],
                'provider_id' => ['nullable', Rule::exists('providers', 'id')->where('business_id', $businessId)],
                'outlet_id' => ['nullable', Rule::exists('outlets', 'id')->where('business_id', $businessId)],
            ] : [
                'is_active' => ['sometimes', 'boolean'],
            ]),
            'collected_via' => ['sometimes', Rule::in(['cash', 'wallet'])],
            'tiers' => ['required', 'array', 'min:1', 'max:10'],
            'tiers.*.min_amount' => ['required', 'integer', 'min:0'],
            'tiers.*.max_amount' => ['nullable', 'integer', 'gt:tiers.*.min_amount'],
            'tiers.*.flat' => ['required', 'integer', 'min:0', 'max:100000000'],
            'tiers.*.percent_bps' => ['required', 'integer', 'min:0', 'max:10000'],
            'tiers.*.cap' => ['nullable', 'integer', 'min:0'],
            'tiers.*.step' => ['nullable', 'integer', 'min:100'],
        ]);

        // Normalise each band to plain integers (kobo / basis points), lowest band first.
        $tiers = collect($request->array('tiers'))
            ->map(fn (array $tier): array => [
                'min_amount' => (int) $tier['min_amount'],
                'max_amount' => isset($tier['max_amount']) ? (int) $tier['max_amount'] : null,
                'flat' => (int) $tier['flat'],
                'percent_bps' => (int) $tier['percent_bps'],
                'cap' => isset($tier['cap']) ? (int) $tier['cap'] : null,
                'step' => isset($tier['step']) ? (int) $tier['step'] : null,
            ])
            ->sortBy('min_amount')
            ->values();

        // Bands must not overlap, and only the last one may be open-ended.
        foreach ($tiers as $i => $tier) {
            $next = $tiers->get($i + 1);

            if ($next !== null && ($tier['max_amount'] === null || $tier['max_amount'] >= $next['min_amount'])) {
                throw ValidationException::withMessages(['tiers' => 'The amount bands overlap. Each band must end before the next one starts.']);
            }
        }

        $validated['tiers'] = $tiers->all();

        return $validated;
    }
}
