<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the composer.json platform requirement for the amqp extension.
 *
 * @see https://github.com/crazy-goat/the-consoomer/issues/240
 */
class ComposerJsonTest extends TestCase
{
    public function testExtAmqpIsPinnedToAVersionConstraint(): void
    {
        $composer = $this->loadComposerJson();

        self::assertArrayHasKey('require', $composer, 'composer.json must declare "require"');
        self::assertArrayHasKey('ext-amqp', $composer['require'], 'composer.json must require ext-amqp');

        $constraint = $composer['require']['ext-amqp'];

        // An unconstrained "*" lets an ancient, unsupported extension install cleanly
        // and then fatal at runtime instead of failing at install time (#240).
        self::assertNotEquals('*', $constraint, 'ext-amqp must not be unconstrained ("*")');

        // PHP 8.4+ requires the 2.x line of the amqp extension.
        self::assertStringStartsWith('^2', $constraint, 'ext-amqp must be pinned to the 2.x line (^2.0)');
    }

    /**
     * @return array<string, mixed>
     */
    private function loadComposerJson(): array
    {
        $path = dirname(__DIR__, 2) . '/composer.json';

        self::assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        self::assertIsArray($decoded, 'composer.json must be valid JSON');

        return $decoded;
    }
}
