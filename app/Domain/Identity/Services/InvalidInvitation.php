<?php

namespace App\Domain\Identity\Services;

use RuntimeException;

/** SaaS.2: an invitation link that cannot be used. The message is the same whatever the reason. */
final class InvalidInvitation extends RuntimeException {}
