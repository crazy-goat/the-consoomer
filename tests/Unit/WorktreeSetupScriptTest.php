<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Runs bin/worktree-setup.sh in a temporary directory with a fake composer on PATH.
 *
 * @see https://github.com/crazy-goat/the-consoomer/issues/433
 */
class WorktreeSetupScriptTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/worktree-setup-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/stub', 0777, true);

        $log = $this->dir . '/composer.log';
        file_put_contents(
            $this->dir . '/stub/composer',
            sprintf("#!/bin/sh\necho \"\$* RABBITMQ_PORT=\${RABBITMQ_PORT:-unset}\" >> %s\n", escapeshellarg($log)),
        );
        chmod($this->dir . '/stub/composer', 0755);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
    }

    public function testRunsWithoutEnvWorktreeFileAndUsesDefaults(): void
    {
        [$exitCode, $output] = $this->runSetup();

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertSame([
            'install --no-interaction --prefer-dist RABBITMQ_PORT=unset',
            'rabbitmq-start RABBITMQ_PORT=unset',
            'rabbitmq-wait RABBITMQ_PORT=unset',
        ], $this->composerLog());
    }

    public function testLoadsEnvWorktreeWhenItExists(): void
    {
        file_put_contents($this->dir . '/.env.worktree', "RABBITMQ_PORT=58658\n");

        [$exitCode, $output] = $this->runSetup();

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertSame([
            'install --no-interaction --prefer-dist RABBITMQ_PORT=58658',
            'rabbitmq-start RABBITMQ_PORT=58658',
            'rabbitmq-wait RABBITMQ_PORT=58658',
        ], $this->composerLog());
    }

    /**
     * @return array{int, list<string>}
     */
    private function runSetup(): array
    {
        $script = dirname(__DIR__, 2) . '/bin/worktree-setup.sh';
        $command = sprintf(
            'cd %s && env -i PATH=%s bash %s 2>&1',
            escapeshellarg($this->dir),
            escapeshellarg($this->dir . '/stub:/usr/bin:/bin'),
            escapeshellarg($script),
        );

        exec($command, $output, $exitCode);

        return [$exitCode, $output];
    }

    /**
     * @return list<string>
     */
    private function composerLog(): array
    {
        $log = $this->dir . '/composer.log';
        if (!is_file($log)) {
            return [];
        }

        return array_values(array_filter(
            explode("\n", (string) file_get_contents($log)),
            static fn(string $line): bool => $line !== '',
        ));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
