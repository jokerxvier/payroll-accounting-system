<?php

declare(strict_types=1);

namespace App\Actions\Contacts;

use RuntimeException;

/**
 * More than one existing contact matches an imported parent.
 *
 * Raised rather than resolved because the two outcomes are not symmetric:
 * importing nothing and saying why costs someone a minute, while silently
 * merging two different people into one payer produces a receivable nobody can
 * untangle and invoices addressed to the wrong family.
 */
final class AmbiguousContactMatch extends RuntimeException {}
