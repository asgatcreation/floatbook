<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits tenant models to the current business. With no business set (and not in
 * system mode) the query matches nothing, so a missing tenant never leaks data.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
class BusinessScope implements Scope
{
    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenancy = app(CurrentBusiness::class);

        if ($tenancy->isSystem()) {
            return;
        }

        $businessId = $tenancy->id();

        if ($businessId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('business_id'), $businessId);
    }
}
