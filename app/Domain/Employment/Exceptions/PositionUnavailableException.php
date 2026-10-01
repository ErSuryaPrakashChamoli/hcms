<?php

namespace App\Domain\Employment\Exceptions;

use InvalidArgumentException;

/** Phase 10: the position named in an assignment cannot take it (not in force, frozen, full, abolished…). */
class PositionUnavailableException extends InvalidArgumentException {}
