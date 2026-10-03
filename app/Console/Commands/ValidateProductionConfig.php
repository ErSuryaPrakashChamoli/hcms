<?php

namespace App\Console\Commands;

use App\Support\Observability\ProductionConfigValidator;
use Illuminate\Console\Command;

/**
 * Production readiness closure: validates the configuration for production traffic. It prints each
 * finding's key, category (missing / insecure / invalid / incompatible / advisory) and rule, never a
 * value, and exits non-zero on any error. Use --as-production to check a staging or pre-production
 * environment against production rules.
 */
class ValidateProductionConfig extends Command
{
    protected $signature = 'peopleos:config:validate {--as-production : Apply production rules whatever APP_ENV says} {--json : Print JSON}';

    protected $description = 'Validate the configuration for production (never prints secret values; non-zero exit on errors)';

    public function handle(ProductionConfigValidator $validator): int
    {
        $findings = $validator->validate((bool) $this->option('as-production'));
        if ($this->option('json')) {
            $this->line(json_encode($findings, JSON_PRETTY_PRINT));
        } elseif ($findings === []) {
            $this->info('No configuration findings.');
        } else {
            $this->table(['Severity', 'Category', 'Key', 'Rule'], array_map(fn ($f) => [$f['severity'], $f['category'], $f['key'], $f['message']], $findings));
        }
        $errors = collect($findings)->where('severity', 'error')->count();
        $this->line(sprintf('%d error(s), %d warning(s).', $errors, count($findings) - $errors));

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
