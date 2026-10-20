<?php

namespace App\Domain\ServiceDesk\Exceptions;

use RuntimeException;

/** Phase 12: a service-desk rule refused the operation (lifecycle, access, separation of duties, validation). */
final class ServiceDeskRuleViolation extends RuntimeException {}
