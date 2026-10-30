<?php

namespace App\Domain\Expense\Exceptions;

use RuntimeException;

/** HROne setup parity (Expense): an expense rule refused the operation (master validation, policy, limits). */
final class ExpenseRuleViolation extends RuntimeException {}
