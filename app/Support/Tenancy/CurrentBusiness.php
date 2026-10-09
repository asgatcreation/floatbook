<?php

namespace App\Support\Tenancy;

use App\Models\Business;
use App\Models\Membership;
use Closure;
use LogicException;

/**
 * Holds the business (tenant) the current request or job is acting for.
 *
 * Tenant models are filtered by this value. When no business is set, tenant queries
 * return nothing (fail closed). Code that legitimately needs to see every business,
 * such as the demo reset, must opt in explicitly with asSystem().
 */
class CurrentBusiness
{
    private ?Business $business = null;

    private ?Membership $membership = null;

    private bool $system = false;

    public function set(Business $business, ?Membership $membership = null): void
    {
        if ($membership !== null && $membership->business_id !== $business->id) {
            throw new LogicException('The membership does not belong to the business.');
        }

        $this->business = $business;
        $this->membership = $membership;
    }

    public function clear(): void
    {
        $this->business = null;
        $this->membership = null;
    }

    public function business(): ?Business
    {
        return $this->business;
    }

    public function businessOrFail(): Business
    {
        return $this->business ?? throw new LogicException('No current business is set.');
    }

    public function id(): ?string
    {
        return $this->business?->id;
    }

    public function membership(): ?Membership
    {
        return $this->membership;
    }

    public function membershipOrFail(): Membership
    {
        return $this->membership ?? throw new LogicException('No current membership is set.');
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    /**
     * Run a callback as a given business, restoring the previous state afterwards.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(Business $business, Closure $callback, ?Membership $membership = null): mixed
    {
        [$previousBusiness, $previousMembership] = [$this->business, $this->membership];
        $this->set($business, $membership);

        try {
            return $callback();
        } finally {
            [$this->business, $this->membership] = [$previousBusiness, $previousMembership];
        }
    }

    /**
     * Run a callback that may read and write across all businesses.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function asSystem(Closure $callback): mixed
    {
        $previous = $this->system;
        $this->system = true;

        try {
            return $callback();
        } finally {
            $this->system = $previous;
        }
    }
}
