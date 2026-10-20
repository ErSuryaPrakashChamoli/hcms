<?php

namespace App\Domain\Platform\Support;

use RuntimeException;

/** SaaS.8: a tenant lifecycle operation refused for a business reason (shown to the operator as is). */
final class LifecycleRefused extends RuntimeException {}
