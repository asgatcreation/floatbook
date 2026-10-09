<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Thrown when code tries to read or write data outside the current business.
 * This always indicates a bug, never a user error.
 */
class TenancyViolation extends RuntimeException {}
