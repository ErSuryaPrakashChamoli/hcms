<?php

namespace App\Domain\Attendance\Enums;

/** A holiday library list: draft (uploaded) → published (tenants can adopt from it) → withdrawn. */
enum HolidayLibraryStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Withdrawn = 'withdrawn';
}
