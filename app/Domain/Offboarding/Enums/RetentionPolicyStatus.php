<?php

namespace App\Domain\Offboarding\Enums;

/** SaaS.9: a retention policy version. pending (proposed) → approved by another operator, or rejected / withdrawn. */
enum RetentionPolicyStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
}
