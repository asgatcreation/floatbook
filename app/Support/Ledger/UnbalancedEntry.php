<?php

namespace App\Support\Ledger;

use LogicException;

/**
 * A journal entry whose lines don't add up to zero. Always a programming error.
 */
class UnbalancedEntry extends LogicException {}
