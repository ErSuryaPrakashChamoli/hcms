<?php

namespace App\Domain\Compensation\Exceptions;

use RuntimeException;

/** A compensation rule refused the action (approval boundary, effective dating, separation of duties …). */
class CompensationRuleViolation extends RuntimeException {}
