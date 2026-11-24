<?php

namespace App\Domain\Organisation\Resolution;

use RuntimeException;

/** A mapping or a missing-master creation that was not allowed. The message is shown to the person as it is. */
final class ResolutionRefusedException extends RuntimeException {}
