<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\User;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Writes entries to the append-only audit log: who did what, to what, when, and the
 * before/after values. Sensitive attributes are never recorded.
 */
class Audit
{
    /** Attributes that must never appear in the audit trail. */
    private const REDACTED = [
        'password', 'remember_token', 'pin_hash', 'token_hash', 'code_hash',
        'two_factor_secret', 'two_factor_recovery_codes',
    ];

    public function __construct(private CurrentBusiness $tenancy) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $meta
     */
    public function record(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        array $meta = [],
        ?Business $business = null,
        ?User $actor = null,
    ): AuditLog {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $actor ??= auth()->user();
        $membership = $this->tenancy->membership();

        $log = new AuditLog;
        $log->forceFill([
            'business_id' => $business->id ?? $this->tenancy->id(),
            'actor_user_id' => $actor?->id,
            'actor_role' => $membership !== null && $membership->user_id === $actor?->id ? $membership->role->value : null,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject ? (string) $subject->getKey() : null,
            'before' => $this->clean($before),
            'after' => $this->clean($after),
            'meta' => $meta === [] ? null : $meta,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
        ])->save();

        return $log;
    }

    /**
     * Record a change to a model using its dirty attributes. Call this before save().
     *
     * @param  array<string, mixed>  $meta
     */
    public function recordChanges(string $action, Model $model, array $meta = []): ?AuditLog
    {
        $dirty = array_keys($model->getDirty());

        if ($dirty === []) {
            return null;
        }

        $before = [];
        foreach ($dirty as $key) {
            $before[$key] = $model->getOriginal($key);
        }

        return $this->record($action, $model, $before, $model->only($dirty), $meta);
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function clean(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach (self::REDACTED as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[redacted]';
            }
        }

        return array_map(fn ($value) => $value instanceof \BackedEnum ? $value->value : $value, $values);
    }
}
