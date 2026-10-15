<?php

namespace App\Http\Controllers;

use App\Models\Outlet;
use App\Models\OutletWallet;
use App\Models\Provider;
use App\Models\Terminal;
use App\Support\Audit;
use App\Support\Ledger\Accounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Owners add the POS terminals each outlet runs, from any provider, including bank POS.
 *
 * A terminal's provider and wallet are fixed once it is added, because its transactions
 * are already in that wallet's books. To change them, switch the terminal off and add a
 * new one.
 */
class TerminalController extends Controller
{
    public function __construct(private Audit $audit, private Accounts $accounts) {}

    public function store(Request $request, Outlet $outlet): RedirectResponse
    {
        $validated = $request->validate([
            ...$this->detailRules($outlet),
            'provider_id' => ['required', 'string'],
            // "new" for a balance of its own, or the id of a wallet it shares.
            'wallet' => ['required', 'string'],
        ]);

        $provider = Provider::query()->active()->whereKey($validated['provider_id'])->first();

        if ($provider === null) {
            throw ValidationException::withMessages(['provider_id' => 'Choose a provider.']);
        }

        $shared = null;

        if ($validated['wallet'] !== 'new') {
            $shared = OutletWallet::query()->where('outlet_id', $outlet->id)->where('provider_id', $provider->id)->whereKey($validated['wallet'])->first();

            if ($shared === null) {
                throw ValidationException::withMessages(['wallet' => 'Choose a balance with the same provider at this outlet.']);
            }
        }

        $terminal = DB::transaction(function () use ($outlet, $provider, $shared, $validated): Terminal {
            $wallet = $shared ?? $this->accounts->openWallet($outlet, $provider, "{$validated['name']} · {$provider->name}");

            return Terminal::query()->create([
                'outlet_id' => $outlet->id,
                'provider_id' => $provider->id,
                'outlet_wallet_id' => $wallet->id,
                'name' => $validated['name'],
                'terminal_code' => $validated['terminal_code'] ?? null,
                'serial_number' => $validated['serial_number'] ?? null,
            ]);
        });

        $this->audit->record('terminal.added', $terminal, after: [
            'outlet' => $outlet->name, 'name' => $terminal->name, 'provider' => $provider->name,
            'terminal_code' => $terminal->terminal_code, 'shares_balance' => $shared !== null,
        ]);
        $this->toast("{$terminal->name} added to {$outlet->name}.");

        return back();
    }

    public function update(Request $request, Terminal $terminal): RedirectResponse
    {
        $terminal->fill($request->validate([
            ...$this->detailRules($terminal->outlet, $terminal),
            'is_active' => ['sometimes', 'boolean'],
        ]));
        $this->audit->recordChanges('terminal.updated', $terminal);
        $terminal->save();

        $this->toast("{$terminal->name} updated.");

        return back();
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function detailRules(Outlet $outlet, ?Terminal $terminal = null): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:40',
                Rule::unique('terminals')->where('outlet_id', $outlet->id)->ignore($terminal?->id)],
            'terminal_code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9 -]+$/'],
            'serial_number' => ['nullable', 'string', 'max:40'],
        ];
    }
}
