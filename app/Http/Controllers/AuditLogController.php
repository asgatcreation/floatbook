<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:60'],
        ]);

        $logs = AuditLog::query()
            ->with('actor:id,name')
            ->when($validated['action'] ?? null, fn ($query, $action) => $query->where('action', 'like', $action.'%'))
            ->latest('created_at')
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor?->name,
                'actor_role' => $log->actor_role,
                'subject' => $log->subject_type,
                'before' => $log->before,
                'after' => $log->after,
                'meta' => $log->meta,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at->toIso8601String(),
            ]);

        $actions = AuditLog::query()->distinct()->orderBy('action')->pluck('action')
            ->map(fn (string $action) => explode('.', $action)[0])->unique()->values();

        return Inertia::render('audit/Index', [
            'logs' => $logs,
            'actions' => $actions,
            'filters' => ['action' => $validated['action'] ?? null],
        ]);
    }
}
