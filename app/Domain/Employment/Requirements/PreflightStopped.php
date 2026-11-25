<?php

namespace App\Domain\Employment\Requirements;

use RuntimeException;

/** Thrown between batches when a pre-flight run was superseded or cancelled while it ran: it stops, nothing is kept. */
final class PreflightStopped extends RuntimeException {}
