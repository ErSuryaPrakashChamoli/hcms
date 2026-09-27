<?php

namespace App\Domain\Compliance\Services\Returns;

use App\Domain\Compliance\Contracts\StatutoryReturnGenerator;
use RuntimeException;

/** Maps a return type to its generator (config peopleos.compliance.return_types). */
final class ReturnGenerators
{
    public function for(string $type): StatutoryReturnGenerator
    {
        $class = config("peopleos.compliance.return_types.{$type}.generator");

        if ($class === null) {
            throw new RuntimeException("No generator is registered for statutory return type [{$type}].");
        }

        return app($class);
    }
}
