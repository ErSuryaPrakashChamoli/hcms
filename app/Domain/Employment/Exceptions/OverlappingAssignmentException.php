<?php

namespace App\Domain\Employment\Exceptions;

use InvalidArgumentException;

/** An effective-dated assignment would overlap or precede an existing one (contract §3, §14). */
class OverlappingAssignmentException extends InvalidArgumentException {}
