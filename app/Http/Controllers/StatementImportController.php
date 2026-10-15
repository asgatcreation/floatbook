<?php

namespace App\Http\Controllers;

use App\Actions\Statements\StatementImports;
use App\Actions\Transactions\RecordTransaction;
use App\Enums\TransactionType;
use App\Models\Outlet;
use App\Models\StatementImport;
use App\Models\StatementImportRow;
use App\Models\Terminal;
use App\Models\Transaction;
use App\Support\Statements\StatementAdapter;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StatementImportController extends Controller
{
    public function __construct(private CurrentBusiness $tenancy, private StatementImports $imports) {}

    public function index(): Response
    {
        $me = $this->tenancy->membershipOrFail();

        return Inertia::render('imports/Index', [
            'imports' => StatementImport::query()->with(['outlet:id,name', 'provider:id,name,color', 'terminal.provider:id,name', 'uploader:id,name'])
                ->latest()->limit(30)->get()
                ->filter(fn (StatementImport $i) => $me->coversOutlet($i->outlet_id))
                ->map(fn (StatementImport $i) => [
                    'id' => $i->id,
                    'file' => $i->original_filename,
                    'outlet' => $i->outlet->name,
                    'provider' => $i->provider->only(['name', 'color']),
                    'terminal' => $i->terminal->label(),
                    'status' => $i->status,
                    'rows' => $i->row_count,
                    'uploaded_by' => $i->uploader?->name,
                    'created_at' => $i->created_at?->toIso8601String(),
                ])->values(),
            'outlets' => Outlet::query()->active()->orderBy('name')->get(['id', 'name'])->filter(fn (Outlet $o) => $me->coversOutlet($o))->values(),
            'terminals' => Terminal::query()->active()->with('provider:id,name,color')->orderBy('name')->get()
                ->filter(fn (Terminal $t) => $me->coversOutlet($t->outlet_id) && $t->provider->is_active)
                ->map(fn (Terminal $t) => [
                    'id' => $t->id, 'outlet_id' => $t->outlet_id, 'label' => $t->label(), 'code' => $t->terminal_code,
                    'color' => $t->provider->color,
                ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $me = $this->tenancy->membershipOrFail();
        $businessId = $this->tenancy->id();

        $validated = $request->validate([
            'terminal_id' => ['required', Rule::exists('terminals', 'id')->where('business_id', $businessId)],
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt,xlsx'],
        ], ['terminal_id.required' => 'Choose the terminal this statement is for.', 'file.mimes' => 'Upload a .csv or .xlsx file.', 'file.max' => 'The file must be smaller than 5 MB.']);

        $terminal = Terminal::query()->with(['provider', 'outlet'])->whereKey($request->string('terminal_id')->value())->firstOrFail();
        abort_unless($me->coversOutlet($terminal->outlet_id), 403);

        $import = $this->imports->upload($me, $terminal, $request->file('file'));

        return redirect()->route('imports.show', $import);
    }

    public function show(StatementImport $import): Response
    {
        $this->authorizeImport($import);
        $import->load(['outlet:id,name', 'provider:id,name,color', 'terminal.provider:id,name']);

        $rows = $import->rows()->with('transaction:id,reference,amount,occurred_at,type')->get();

        return Inertia::render('imports/Show', [
            'statement' => [
                'id' => $import->id,
                'file' => $import->original_filename,
                'outlet' => $import->outlet->name,
                'provider' => $import->provider->only(['name', 'color']),
                'terminal' => $import->terminal->label(),
                'status' => $import->status,
                'headers' => $import->headers,
                'mapping' => $import->column_mapping,
                'row_count' => $import->row_count,
            ],
            'fields' => StatementAdapter::FIELDS,
            'required' => StatementAdapter::REQUIRED,
            'sample' => $rows->take(5)->map(fn (StatementImportRow $r) => $r->raw)->values(),
            'rows' => $import->status === 'uploaded' ? [] : $rows->map(fn (StatementImportRow $r) => [
                'id' => $r->id,
                'row_number' => $r->row_number,
                'reference' => $r->reference,
                'amount' => $r->amount,
                'occurred_at' => $r->occurred_at?->toIso8601String(),
                'type' => $r->type,
                'provider_status' => $r->provider_status,
                'match_status' => $r->match_status,
                'problem' => $r->problem,
                'transaction_id' => $r->matched_transaction_id,
            ])->values(),
            'notOnStatement' => $import->status === 'uploaded' ? [] : $this->imports->missingFromStatement($import)
                ->map(fn (Transaction $t) => [
                    'id' => $t->id, 'type_label' => $t->type->label(), 'amount' => $t->amount,
                    'reference' => $t->reference, 'occurred_at' => $t->occurred_at->toIso8601String(),
                ]),
            'types' => collect(TransactionType::cases())->map(fn (TransactionType $t) => ['value' => $t->value, 'label' => $t->label()]),
        ]);
    }

    public function map(Request $request, StatementImport $import): RedirectResponse
    {
        $this->authorizeImport($import);

        $validated = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string', Rule::in($import->headers)],
        ]);

        $mapping = collect(StatementAdapter::FIELDS)->mapWithKeys(fn (string $f) => [$f => $validated['mapping'][$f] ?? null])->all();
        $this->imports->analyse($import, $mapping);

        return redirect()->route('imports.show', $import);
    }

    public function commit(Request $request, StatementImport $import, RecordTransaction $record): RedirectResponse
    {
        $this->authorizeImport($import);

        $validated = $request->validate([
            'row_ids' => ['array'],
            'row_ids.*' => ['integer'],
            'default_type' => ['required', Rule::enum(TransactionType::class)],
        ]);

        $created = $this->imports->commit(
            $this->tenancy->membershipOrFail(), $import, array_values(array_map('intval', $validated['row_ids'] ?? [])),
            TransactionType::from($validated['default_type']), $record,
        );

        $this->toast($created === 0 ? 'Statement checked. Nothing was added.' : "Done. {$created} missing ".($created === 1 ? 'transaction was' : 'transactions were').' added to FloatBook.');

        return redirect()->route('imports.show', $import);
    }

    private function authorizeImport(StatementImport $import): void
    {
        abort_unless($this->tenancy->membershipOrFail()->coversOutlet($import->outlet_id), 404);
    }
}
