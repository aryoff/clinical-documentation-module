<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Console;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Modules\ClinicalDocumentation\Services\ClinicalRecordDestruction;

/**
 * Retries the remaining steps of a destruction that a person started. A worker can finish it; it can never start one,
 * and it stops as soon as any step is no longer allowed.
 */
final class ResumeDestruction extends Command
{
    protected $signature = 'clinicaldocumentation:resume-destruction {destruction : The destruction id}';

    protected $description = 'Retry the remaining steps of a started clinical record destruction.';

    public function handle(ClinicalRecordDestruction $destruction): int
    {
        try {
            $record = $destruction->resume((string) $this->argument('destruction'));
        } catch (ValidationException $refusal) {
            $this->error(implode(' ', array_merge(...array_values($refusal->errors()))));

            return self::FAILURE;
        }
        $this->info('Destruction is '.$destruction->status($record).'.');

        return self::SUCCESS;
    }
}
