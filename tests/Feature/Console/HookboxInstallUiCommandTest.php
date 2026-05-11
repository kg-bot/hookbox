<?php

declare(strict_types=1);

namespace Hookbox\Tests\Feature\Console;

use Hookbox\Support\ShellCommandRunner;
use Hookbox\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Testing\PendingCommand;

final class HookboxInstallUiCommandTest extends TestCase
{
    public function test_it_registers_the_install_ui_command(): void
    {
        $this->assertArrayHasKey('hookbox:install-ui', $this->appInstance()->make(Kernel::class)->all());
    }

    public function test_it_prints_the_composer_command_and_does_not_execute_it_during_a_dry_run(): void
    {
        $runner = new FakeShellCommandRunner;

        $this->appInstance()->instance(ShellCommandRunner::class, $runner);

        $this->runInstallerCommand([
            'stack' => 'blade',
            '--dry-run' => true,
        ])
            ->expectsOutput('composer require kg-bot/hookbox-blade')
            ->assertExitCode(0);

        $this->assertSame([], $runner->commands);
    }

    public function test_it_runs_the_expected_composer_command_after_confirmation(): void
    {
        $runner = new FakeShellCommandRunner;

        $this->appInstance()->instance(ShellCommandRunner::class, $runner);

        $this->runInstallerCommand([
            'stack' => 'vue',
        ])
            ->expectsOutput('composer require kg-bot/hookbox-vue')
            ->expectsConfirmation('Run this command?', 'yes')
            ->assertExitCode(0);

        $this->assertSame([['composer', 'require', 'kg-bot/hookbox-vue']], $runner->commands);
        $this->assertSame([base_path()], $runner->workingDirectories);
    }

    public function test_force_bypasses_confirmation(): void
    {
        $runner = new FakeShellCommandRunner;

        $this->appInstance()->instance(ShellCommandRunner::class, $runner);

        $this->runInstallerCommand([
            'stack' => 'livewire',
            '--force' => true,
        ])
            ->expectsOutput('composer require kg-bot/hookbox-livewire')
            ->assertExitCode(0);

        $this->assertSame([['composer', 'require', 'kg-bot/hookbox-livewire']], $runner->commands);
        $this->assertSame([base_path()], $runner->workingDirectories);
    }

    public function test_it_fails_for_an_unsupported_stack(): void
    {
        $runner = new FakeShellCommandRunner;

        $this->appInstance()->instance(ShellCommandRunner::class, $runner);

        $this->runInstallerCommand([
            'stack' => 'react',
        ])
            ->expectsOutput('Unsupported UI stack [react]. Supported stacks: blade, vue, livewire, filament.')
            ->assertExitCode(1);

        $this->assertSame([], $runner->commands);
    }

    public function test_it_fails_when_the_composer_command_returns_a_non_zero_exit_code(): void
    {
        $runner = new FakeShellCommandRunner(1);

        $this->appInstance()->instance(ShellCommandRunner::class, $runner);

        $this->runInstallerCommand([
            'stack' => 'filament',
            '--force' => true,
        ])
            ->expectsOutput('composer require kg-bot/hookbox-filament')
            ->expectsOutput('Composer command failed with exit code 1.')
            ->assertExitCode(1);

        $this->assertSame([['composer', 'require', 'kg-bot/hookbox-filament']], $runner->commands);
        $this->assertSame([base_path()], $runner->workingDirectories);
    }

    public function test_it_returns_success_when_the_user_cancels_confirmation(): void
    {
        $runner = new FakeShellCommandRunner;

        $this->appInstance()->instance(ShellCommandRunner::class, $runner);

        $this->runInstallerCommand([
            'stack' => 'blade',
        ])
            ->expectsOutput('composer require kg-bot/hookbox-blade')
            ->expectsConfirmation('Run this command?', 'no')
            ->expectsOutput('Installation cancelled.')
            ->assertExitCode(0);

        $this->assertSame([], $runner->commands);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function runInstallerCommand(array $parameters): PendingCommand
    {
        $result = $this->artisan('hookbox:install-ui', $parameters);

        if (! $result instanceof PendingCommand) {
            throw new \RuntimeException('Expected a pending artisan command instance.');
        }

        return $result;
    }
}

final class FakeShellCommandRunner extends ShellCommandRunner
{
    /**
     * @var list<list<string>>
     */
    public array $commands = [];

    /**
     * @var list<string>
     */
    public array $workingDirectories = [];

    public function __construct(private readonly int $exitCode = 0) {}

    public function run(array $command, string $workingDirectory): int
    {
        $this->commands[] = $command;
        $this->workingDirectories[] = $workingDirectory;

        return $this->exitCode;
    }
}
