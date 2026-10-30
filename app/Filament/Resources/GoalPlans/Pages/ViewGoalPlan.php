<?php

namespace App\Filament\Resources\GoalPlans\Pages;

use App\Filament\Resources\GoalPlans\GoalPlanResource;
use App\Filament\Support\Pages\PeopleViewRecord;

/** HROne parity GO-1: one goal request with its decision and approval trail (read-only). */
class ViewGoalPlan extends PeopleViewRecord
{
    protected static string $resource = GoalPlanResource::class;
}
