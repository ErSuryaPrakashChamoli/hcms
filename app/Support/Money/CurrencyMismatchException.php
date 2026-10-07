<?php

namespace App\Support\Money;

use InvalidArgumentException;

/** SaaS.7: two amounts of different currencies were combined; PeopleOS never converts implicitly. */
final class CurrencyMismatchException extends InvalidArgumentException {}
