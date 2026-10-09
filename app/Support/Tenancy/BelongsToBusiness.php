<?php

namespace App\Support\Tenancy;

use App\Models\Business;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Makes a model tenant-owned: queries are filtered to the current business, new rows
 * are stamped with it, and a row can never be saved into a different business than
 * the one currently active.
 *
 * @mixin Model
 */
trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope(new BusinessScope);

        static::creating(function (Model $model): void {
            $tenancy = app(CurrentBusiness::class);
            $current = $tenancy->id();

            if (! $model->getAttribute('business_id')) {
                if ($current === null) {
                    throw new TenancyViolation('Cannot create '.class_basename($model).' without a current business.');
                }

                $model->setAttribute('business_id', $current);
            }

            static::guardAgainstForeignBusiness($model, $tenancy);
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('business_id')) {
                throw new TenancyViolation('A record cannot be moved to another business.');
            }

            static::guardAgainstForeignBusiness($model, app(CurrentBusiness::class));
        });

        static::deleting(function (Model $model): void {
            static::guardAgainstForeignBusiness($model, app(CurrentBusiness::class));
        });
    }

    protected static function guardAgainstForeignBusiness(Model $model, CurrentBusiness $tenancy): void
    {
        if ($tenancy->isSystem()) {
            return;
        }

        if ($model->getAttribute('business_id') !== $tenancy->id()) {
            throw new TenancyViolation(class_basename($model).' belongs to a different business.');
        }
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
