<?php

namespace App\Domain\Employment\Exceptions;

use RuntimeException;

/** A People / Employment profile change action refused the change (authorization or validation). */
class ProfileChangeRefused extends RuntimeException {}
