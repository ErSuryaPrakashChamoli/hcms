<?php

namespace App\Domain\Engagement\Exceptions;

use RuntimeException;

/** Phase 13: an engagement or communication rule refused the operation. */
final class EngagementRuleViolation extends RuntimeException {}
