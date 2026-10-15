<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A wallet's balance at close: what the books expected and what the terminal showed.
 *
 * @property string $id
 * @property string $business_id
 * @property string $reconciliation_id
 * @property string $outlet_wallet_id
 * @property int $expected
 * @property int $actual
 * @property int $variance
 * @property-read OutletWallet $wallet
 */
#[Fillable(['reconciliation_id', 'outlet_wallet_id', 'expected', 'actual', 'variance'])]
class ReconciliationWallet extends Model
{
    use BelongsToBusiness, HasUlids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['expected' => 'integer', 'actual' => 'integer', 'variance' => 'integer'];
    }

    /**
     * @return BelongsTo<OutletWallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(OutletWallet::class, 'outlet_wallet_id');
    }
}
