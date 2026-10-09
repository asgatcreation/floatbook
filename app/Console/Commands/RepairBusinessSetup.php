<?php

namespace App\Console\Commands;

use App\Actions\Business\SetUpNewBusiness;
use App\Models\Business;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('floatbook:repair-setup')]
#[Description('Add any missing providers, ledger accounts and starter charge rules to existing businesses')]
class RepairBusinessSetup extends Command
{
    public function handle(SetUpNewBusiness $setUp): int
    {
        Business::query()->each(function (Business $business) use ($setUp): void {
            $setUp($business);
            $this->components->task($business->name);
        });

        return self::SUCCESS;
    }
}
