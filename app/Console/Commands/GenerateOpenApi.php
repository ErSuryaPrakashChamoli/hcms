<?php

namespace App\Console\Commands;

use App\Support\Api\OpenApiGenerator;
use Illuminate\Console\Command;

class GenerateOpenApi extends Command
{
    protected $signature = 'peopleos:openapi {--check : Fail when the committed docs/api/openapi.json is out of date}';

    protected $description = 'Generate the OpenAPI 3.1 description of the public API (docs/api/openapi.json) from the route table';

    public const PATH = 'docs/api/openapi.json';

    public function handle(OpenApiGenerator $generator): int
    {
        $json = json_encode($generator->generate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        $path = base_path(self::PATH);
        if ($this->option('check')) {
            if (! is_file($path) || file_get_contents($path) !== $json) {
                $this->error(self::PATH.' is out of date; run php artisan peopleos:openapi');

                return self::FAILURE;
            }
            $this->info(self::PATH.' is up to date.');

            return self::SUCCESS;
        }
        file_put_contents($path, $json);
        $this->info('Wrote '.self::PATH.' ('.substr_count($json, '"operationId"').' operations).');

        return self::SUCCESS;
    }
}
