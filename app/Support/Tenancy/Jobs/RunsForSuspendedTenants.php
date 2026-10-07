<?php

namespace App\Support\Tenancy\Jobs;

/**
 * SaaS.7: marks a tenant-aware job that must still run while its tenant is suspended, because it records what
 * already happened outside PeopleOS (a payment provider moved money). Such a job touches only commercial records,
 * never HCM data. The implementers are an exact allow-list (architecture test).
 */
interface RunsForSuspendedTenants {}
