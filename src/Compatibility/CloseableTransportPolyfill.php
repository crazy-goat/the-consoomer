<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer\Compatibility;

/**
 * Loads the compatibility stub for Symfony Messenger's
 * `CloseableTransportInterface` on Symfony versions that predate it (#250).
 *
 * Symfony introduced `CloseableTransportInterface` in 7.3. {@see \CrazyGoat\TheConsoomer\AmqpTransport}
 * implements it unconditionally, so on an older Symfony the interface must be
 * declared before the transport class is autoloaded. The stub is written in
 * Symfony's own namespace because PHP cannot alias an interface into another
 * namespace.
 *
 * Declaring a foreign-namespace interface is fragile under an
 * optimized/classmap autoloader: a classmap generator would index the stub
 * file under `Symfony\Component\Messenger\Transport\CloseableTransportInterface`,
 * colliding with the real interface on Symfony >= 7.3. Two guards prevent that:
 *
 * - the stub directory is listed under `autoload.exclude-from-classmap` in
 *   composer.json, so it is never indexed; and
 * - {@see load()} refuses to declare the stub when the installed
 *   symfony/messenger is >= {@see INTRODUCED_IN}, so a broken install on a
 *   version that ships the interface is not papered over by a shadowing stub.
 *
 * The loader is executed once through composer's `autoload.files`, so the
 * interface exists before any transport class is loaded.
 */
final class CloseableTransportPolyfill
{
    /** Fully-qualified name declared by both the real interface and the stub. */
    public const INTERFACE_NAME = \Symfony\Component\Messenger\Transport\CloseableTransportInterface::class;

    /** Absolute path to the stub that declares {@see INTERFACE_NAME}. */
    public const STUB_FILE = __DIR__ . '/stub/CloseableTransportInterface.php';

    /** First symfony/messenger release that ships the real interface. */
    public const INTRODUCED_IN = '7.3.0';

    /**
     * Decides whether the stub has to be declared.
     *
     * Kept pure so the version gate can be unit-tested without actually
     * declaring an interface in Symfony's namespace (which would be fatal
     * in a test run against Symfony >= 7.3).
     *
     * @param bool        $interfaceExists  Whether the real interface is already available
     * @param string|null $messengerVersion Installed symfony/messenger version, or null when unknown
     */
    public static function shouldLoad(bool $interfaceExists, ?string $messengerVersion): bool
    {
        if ($interfaceExists) {
            return false;
        }

        // On a Symfony that ships the interface, its absence means a broken
        // install rather than an old Symfony: never shadow the real one.
        if ($messengerVersion !== null && version_compare($messengerVersion, self::INTRODUCED_IN, '>=')) {
            return false;
        }

        return true;
    }

    /**
     * Declares the stub when needed.
     *
     * Both parameters default to live detection for the production call at the
     * bottom of this file; tests pass them explicitly to exercise the gate
     * without loading the stub.
     *
     * @param bool|null   $interfaceExists  Overrides the `interface_exists()` probe
     * @param string|null $messengerVersion Overrides the installed-version probe
     */
    public static function load(?bool $interfaceExists = null, ?string $messengerVersion = null): void
    {
        $interfaceExists ??= interface_exists(self::INTERFACE_NAME);
        $messengerVersion ??= self::detectMessengerVersion();

        if (!self::shouldLoad($interfaceExists, $messengerVersion)) {
            return;
        }

        require_once self::STUB_FILE;
    }

    /**
     * Returns the normalized installed symfony/messenger version, if known.
     *
     * `Composer\InstalledVersions` is shipped by Composer 2 and present in any
     * Composer-managed install; the class_exists() probe keeps this safe in the
     * unusual case where the polyfill file is loaded without it.
     */
    private static function detectMessengerVersion(): ?string
    {
        if (!class_exists(\Composer\InstalledVersions::class)) {
            return null;
        }

        try {
            $version = \Composer\InstalledVersions::getVersion('symfony/messenger');
        } catch (\OutOfRangeException) {
            return null;
        }

        if ($version === null) {
            return null;
        }

        // getVersion() may return a leading "v" or a dev suffix; version_compare
        // handles the suffix and the stripped prefix.
        return preg_replace('/^v/i', '', $version) ?? $version;
    }
}

CloseableTransportPolyfill::load();
