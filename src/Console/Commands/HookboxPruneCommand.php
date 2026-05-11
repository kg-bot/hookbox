<?php

declare(strict_types=1);

namespace Hookbox\Console\Commands;

use Hookbox\Models\WebhookMessage;
use Illuminate\Console\Command;

final class HookboxPruneCommand extends Command
{
    protected $signature = 'hookbox:prune
                            {--chunk=1000 : The number of models to retrieve per chunk of models to be deleted}
                            {--pretend : Display the number of prunable records found instead of deleting them}';

    protected $description = 'Prune expired Hookbox webhook messages';

    public function handle(): int
    {
        $chunk = $this->option('chunk');

        if (! is_int($chunk) && ! is_string($chunk)) {
            throw new \UnexpectedValueException('Prune chunk option must be an integer or string value.');
        }

        return $this->call('model:prune', [
            '--model' => [WebhookMessage::class],
            '--chunk' => $chunk,
            '--pretend' => (bool) $this->option('pretend'),
        ]);
    }
}
