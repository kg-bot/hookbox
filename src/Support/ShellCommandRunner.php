<?php

declare(strict_types=1);

namespace Hookbox\Support;

class ShellCommandRunner
{
    /**
     * @param  list<string>  $command
     */
    public function run(array $command, string $workingDirectory): int
    {
        $parts = array_map(static fn (string $part): string => escapeshellarg($part), $command);
        $shellCommand = implode(' ', $parts);
        $currentWorkingDirectory = getcwd();

        if ($currentWorkingDirectory === false) {
            throw new \RuntimeException('Unable to determine the current working directory.');
        }

        if (! @chdir($workingDirectory)) {
            throw new \RuntimeException(sprintf('Unable to change directory to [%s].', $workingDirectory));
        }

        try {
            passthru($shellCommand, $exitCode);
        } finally {
            chdir($currentWorkingDirectory);
        }

        return $exitCode;
    }
}
