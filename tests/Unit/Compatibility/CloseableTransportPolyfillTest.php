<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Tests\Unit\Compatibility;

use CrazyGoat\TheConsoomer\Compatibility\CloseableTransportPolyfill;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\CloseableTransportInterface;

/**
 * Guard tests for the vendor-namespace compatibility polyfill (#250).
 */
class CloseableTransportPolyfillTest extends TestCase
{
    public function testDoesNotLoadWhenInterfaceAlreadyExists(): void
    {
        $this->assertFalse(CloseableTransportPolyfill::shouldLoad(true, null));
        $this->assertFalse(CloseableTransportPolyfill::shouldLoad(true, '6.4.0'));
    }

    /**
     * @return list<array{0: string|null}>
     */
    public static function olderVersionsProvider(): array
    {
        return [[null], ['6.4.0'], ['6.4.9999999.9999999-dev'], ['7.2.9']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('olderVersionsProvider')]
    public function testLoadsOnSymfonyWithoutTheInterface(?string $version): void
    {
        $this->assertTrue(CloseableTransportPolyfill::shouldLoad(false, $version));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function newVersionsProvider(): array
    {
        return [['7.3.0'], ['7.4.0'], ['8.0.12.0']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('newVersionsProvider')]
    public function testDoesNotShadowTheRealInterfaceOnSymfony73OrNewer(string $version): void
    {
        $this->assertFalse(CloseableTransportPolyfill::shouldLoad(false, $version));
    }

    /**
     * The current dev install ships the interface, so the production call at
     * the bottom of the polyfill file must be a no-op — invoking it again must
     * not attempt to declare a competing interface.
     */
    public function testLoadIsANoOpWhenTheInterfaceExists(): void
    {
        if (!interface_exists(CloseableTransportPolyfill::INTERFACE_NAME)) {
            $this->markTestSkipped('Interface not provided by the installed Symfony version.');
        }

        CloseableTransportPolyfill::load();

        $this->assertTrue(interface_exists(CloseableTransportPolyfill::INTERFACE_NAME));
    }

    /**
     * Even with a broken interface_exists() result, the version gate stops the
     * stub from being declared on a Symfony that ships the real interface.
     */
    public function testLoadDoesNotDeclareStubOnNewerSymfonyEvenIfProbeFails(): void
    {
        CloseableTransportPolyfill::load(false, '8.0.12.0');

        $this->assertTrue(interface_exists(CloseableTransportPolyfill::INTERFACE_NAME));
    }

    /**
     * The stub must never be classmap-indexed: it declares an interface in
     * Symfony's namespace and would collide with the real one on >= 7.3 (#250).
     */
    public function testStubDirectoryIsExcludedFromClassmap(): void
    {
        $composer = json_decode(
            (string) file_get_contents(\dirname(__DIR__, 3) . '/composer.json'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        $excluded = $composer['autoload']['exclude-from-classmap'] ?? [];

        $this->assertContains('src/Compatibility/stub/', $excluded);
    }

    public function testStubDeclaresTheExpectedInterface(): void
    {
        $reflection = new \ReflectionClass(CloseableTransportInterface::class);

        $this->assertTrue($reflection->hasMethod('close'));
        $this->assertSame('close', $reflection->getMethod('close')->getName());
        $this->assertCount(0, $reflection->getMethod('close')->getParameters());
    }
}
