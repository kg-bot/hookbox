<?php

declare(strict_types=1);

namespace Hookbox\Console\Commands;

use Hookbox\Support\ShellCommandRunner;
use Illuminate\Console\Command;

final class HookboxInstallUiCommand extends Command
{
    protected $signature = 'hookbox:install-ui {stack} {--dry-run} {--force}';

    protected $description = 'Install a Hookbox UI package';

    /**
     * @var array<string, string>
     */
    private array $packages = [
        'blade' => 'kg-bot/hookbox-blade',
        'vue' => 'kg-bot/hookbox-vue',
        'livewire' => 'kg-bot/hookbox-livewire',
        'filament' => 'kg-bot/hookbox-filament',
    ];

    public function __construct(private readonly ShellCommandRunner $runner)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $stackArgument = $this->argument('stack');

        if (! is_string($stackArgument) && ! is_int($stackArgument)) {
            throw new \UnexpectedValueException('UI stack argument must be a string or integer value.');
        }

        $stack = strtolower((string) $stackArgument);
        $package = $this->packages[$stack] ?? null;

        if ($package === null) {
            $supportedStacks = implode(', ', array_keys($this->packages));

            $this->line(sprintf(
                'Unsupported UI stack [%s]. Supported stacks: %s.',
                $stack,
                $supportedStacks,
            ));

            return self::FAILURE;
        }

        $command = ['composer', 'require', $package];
        $displayCommand = implode(' ', $command);

        $this->line($displayCommand);

        if ((bool) $this->option('dry-run')) {
            return self::SUCCESS;
        }

        if (! (bool) $this->option('force') && ! $this->confirm('Run this command?')) {
            $this->line('Installation cancelled.');

            return self::SUCCESS;
        }

        $exitCode = $this->runner->run($command, base_path());

        if ($exitCode !== 0) {
            $this->line(sprintf('Composer command failed with exit code %d.', $exitCode));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
