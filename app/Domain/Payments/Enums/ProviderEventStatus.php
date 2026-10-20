<?php

namespace App\Domain\Payments\Enums;

/** SaaS.7: a verified provider event: received → processing → applied | ignored | exception | failed (retried). */
enum ProviderEventStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Applied = 'applied';
    case Ignored = 'ignored';
    case Exception = 'exception';
    case Failed = 'failed';
}
