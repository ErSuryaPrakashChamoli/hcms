<?php

namespace App\Domain\Offboarding\Services;

use RuntimeException;

/** SaaS.9: a legal hold placed while a deletion runs: the run stops before the next batch (fail-safe). */
final class LegalHoldEncountered extends RuntimeException {}
