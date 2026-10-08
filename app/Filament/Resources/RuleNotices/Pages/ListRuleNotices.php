<?php

namespace App\Filament\Resources\RuleNotices\Pages;

use App\Filament\Resources\RuleNotices\RuleNoticeResource;
use App\Filament\Support\Pages\PeopleListRecords;

class ListRuleNotices extends PeopleListRecords
{
    protected static string $resource = RuleNoticeResource::class;
}
