<?php

namespace App\Services\License;

/**
 * Decides which host this install's plugin licenses are bound to, and which
 * hosts count as the same install.
 *
 * Ports never matter — hosts are always compared without them, so
 * `localhost:8000` and `localhost:8001` are the same domain.
 */
final class LicenseDomain
{
    /**
     * Hosts treated as one local dev machine, so a license installed on
     * `localhost` also works on `127.0.0.1` (and vice versa).
     */
    public const LOCAL_HOSTS = ['localhost', '127.0.0.1'];

    /**
     * The domain licenses are bound to: APP_URL's host, lower-cased, without the port.
     */
    public static function current(): string
    {
        return strtolower(parse_url(config('app.url'), PHP_URL_HOST) ?? 'localhost');
    }

    /**
     * Every domain that should count as the same install as the given one.
     *
     * @param  string|null  $domain  Defaults to the current domain.
     * @return list<string>  Both local hosts for `localhost` / `127.0.0.1`, otherwise just the domain itself.
     */
    public static function equivalents(?string $domain = null): array
    {
        $domain = strtolower($domain ?? self::current());

        return in_array($domain, self::LOCAL_HOSTS, true) ? self::LOCAL_HOSTS : [$domain];
    }

    /**
     * Whether two domains belong to the same install (case-insensitive, local hosts interchangeable).
     */
    public static function matches(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null
            && in_array(strtolower($b), self::equivalents($a), true);
    }
}
