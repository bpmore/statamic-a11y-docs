<?php

declare(strict_types=1);

namespace Bpmore\A11yGate\Panel;

/**
 * A11y Gate's seam, stood in for.
 *
 * The gate is an optional companion and is not a dependency of this package,
 * so its classes are absent here. This is loaded only when the real one is,
 * and it carries exactly the one method this addon calls on it.
 *
 * What it proves: that this addon asks before keying a refusal to the panel,
 * and keys it there when the answer is yes. What it cannot prove: that the
 * gate's own panel then draws it. That belongs to the gate, and its suite has
 * a test that reads its panel source for `refusalFor(ext)`.
 */
final class PanelExtensions
{
    /** @var list<string> */
    public static array $capabilities = ['refusalKey'];

    /** @var array<int, callable> */
    public static array $providers = [];

    public static function supports(string $capability): bool
    {
        return in_array($capability, self::$capabilities, true);
    }

    /**
     * Here because the service provider calls it the moment this class is
     * found. Its absence failed every test in the file with "undefined
     * method", which is a fair description of what would happen on a site
     * whose gate was too old, and is the reason `supports()` exists.
     */
    public static function register(callable $provider): void
    {
        self::$providers[] = $provider;
    }

    public static function flush(): void
    {
        self::$providers = [];
    }
}
