<?php

namespace App\Domain\Signup\Support;

use RuntimeException;

/** SaaS.10: the provisioning claim is no longer this run's (another run, or a lease expiry): stop without recording anything. */
final class StaleClaim extends RuntimeException {}
