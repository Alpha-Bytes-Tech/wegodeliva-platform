<?php

namespace App\Services\Ledger;

use LogicException;

/** A reference was reused for a different posting: a bug in the caller, never retried. */
final class LedgerConflict extends LogicException {}
