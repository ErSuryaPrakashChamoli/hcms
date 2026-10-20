<?php

namespace App\Domain\Payments\Exceptions;

use RuntimeException;

/** SaaS.7: a provider call failed or the provider cannot do what was asked (e.g. a currency it does not take). */
final class PaymentProviderException extends RuntimeException {}
