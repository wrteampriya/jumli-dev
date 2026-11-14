<?php

namespace App\Services;

use App\Models\ServiceHealthLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class SystemIntegrityService
{

    private const INTERVAL = 10080;
    private const SK = 'X3NyaV90cw=='; // '_sri_ts'
    private const DOWN_KEY = '_sri_down';
    private const DOWN_TTL = 600; // seconds to skip API calls after a network failure

    /**
     * Main entry point for the integrity check.
     * Tiered: Session -> Database -> API
     */

    public static function check(): bool
    {
        try {
            return self::evaluate();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e; // deliberate licensing denial
        } catch (\Throwable $e) {
            // Any unexpected failure (DB, session, network) must never take the customer's panel down.
            Log::error('[Integrity] ' . $e->getMessage());
            return true;
        }
    }

    private static function evaluate(): bool
    {
        // --- Skip check for localhost ---
        $host = parse_url(url('/'), PHP_URL_HOST);
        if (in_array($host, ['localhost', '127.0.0.1', '::1'])) {
            return true;
        }

        // --- Tier 0: Skip if not installed yet ---
        if (!file_exists(storage_path(base64_decode('aW5zdGFsbGVk')))) {
            return true;
        }

        // --- Skip check for essential bypass routes ---
        $bypass = ['login', 'logout', 'install', 'install/*', 'clear', 'migrate', 'storage-link', 'restore-access', 'restore-access/*'];
        foreach ($bypass as $bp) {
            if (request()->is($bp)) {
                return true;
            }
        }

        // --- Tier 1: Session Cache (7 Days) ---
        $sv = session(base64_decode(self::SK));
        if ($sv && Carbon::parse($sv)->diffInMinutes(now()) < self::INTERVAL) {
            return true;
        }

        // --- Check if DB table is ready ---
        if (!self::isTableReady()) {
            return true;
        }

        // --- Tier 2: Database with Integrity Check (HMAC) ---
        $latest = ServiceHealthLog::latest('checked_at')->first();
        if ($latest && $latest->checked_at->diffInMinutes(now()) < self::INTERVAL) {
            // Verify record integrity using APP_KEY
            if (!self::verifyChecksum($latest)) {
                self::deny('Data integrity violation detected.');
            }
            if ($latest->status === 'valid') {
                return true;
            }
            self::deny($latest->response_message);
        }

        // --- Tier 3: External API Verification ---
        return self::verifyWithApi();
    }

    private static function isTableReady(): bool
    {
        $tblStatus = session(base64_decode('X3NobF9pbml0'));
        if ($tblStatus === false)
            return false;
        if ($tblStatus === null) {
            try {
                $tbl = base64_decode('c2VydmljZV9oZWFsdGhfbG9ncw=='); // 'service_health_logs'
                if (!Schema::hasTable($tbl)) {
                    session([base64_decode('X3NobF9pbml0') => false]);
                    return false;
                }
                session([base64_decode('X3NobF9pbml0') => true]);
            } catch (\Throwable $e) {
                return false;
            }
        }
        return true;
    }

    private static function verifyWithApi(): bool
    {
        // Validator recently unreachable: don't retry on every request.
        if (self::isMarkedDown()) {
            return true;
        }

        try {
            $cfg = self::config();
            $code = env($cfg['ek']); // Purchase Code from APPSECRET
            $domain = self::resolveDomain();

            if (empty($code)) {
                self::store('invalid', 'Configuration missing.', $domain);
                self::deny('Configuration missing.');
            }

            $params = [
                base64_decode('cHVyY2hhc2VfY29kZQ==') => $code,
                base64_decode('ZG9tYWluX3VybA==') => $domain
            ];

            $url = $cfg['ep'] . '?' . http_build_query($params);
            $resp = self::req('GET', $url);

            if ($resp === null) {
                self::markDown();
                return true; // Silent on network error to prevent lockouts
            }

            return self::handleResponse($resp, $code, $cfg, $domain);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[Integrity] ' . $e->getMessage());
            self::markDown();
            return true;
        }
    }

    /**
     * Resolve stable domain for the integrity check: strips "www." so
     * www.domain.com and domain.com are treated as the same site, and
     * falls back to APP_URL's host if the request host is an IP address.
     */
    private static function resolveDomain(): string
    {
        $host = parse_url(\url('/'), PHP_URL_HOST) ?? '';

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            $configHost = parse_url((string) config('app.url'), PHP_URL_HOST) ?? '';
            if ($configHost !== '' && !filter_var($configHost, FILTER_VALIDATE_IP)) {
                $host = $configHost;
            }
        }

        return preg_replace('/^www\./i', '', $host);
    }

    /**
     * Interpret the validator response. Only clear licensing verdicts deny access;
     * anything transient or unrecognised is treated like an outage (allow + back off).
     */
    private static function handleResponse(string $resp, string $code, array $cfg, string $domain): bool
    {
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            self::markDown();
            return true;
        }

        if (!self::genuine($data, $code, $cfg)) {
            // Malformed/unexpected body (proxy error page as JSON, maintenance mode, ...): treat as outage.
            self::markDown();
            return true;
        }

        $msg = trim($data['message'] ?? '');
        $verdict = self::verdict($data, $msg);

        switch ($verdict) {
            case 'registered':
                self::store('valid', $msg, $domain);
                session([base64_decode(self::SK) => now()->toDateTimeString()]);
                self::purgeOld();
                return true;

            case 'registered_now':
                // Validator just auto-registered this domain => it was not registered (expired/reset).
                self::revert($code, $domain, $data['username'] ?? '');
                self::store('invalid', 'Domain registration expired.', $domain);
                self::deny('Domain registration expired.');
                return false;

            case 'domain_mismatch':
            case 'invalid_code':
                $msg = $msg !== '' ? $msg : 'Verification failed';
                self::store('invalid', $msg, $domain);
                self::deny($msg);
                return false;

            default:
                // upstream_error, localhost, or anything we do not recognise:
                // never lock the customer or reset their domain on a guess.
                self::markDown();
                return true;
        }
    }

    /**
     * Reduce a validator response to one verdict. Prefers the machine-readable
     * `status` field; falls back to message matching for older validators.
     */
    private static function verdict(array $data, string $msg): string
    {
        $known = ['registered', 'registered_now', 'domain_mismatch', 'invalid_code', 'upstream_error', 'localhost'];
        if (isset($data['status']) && is_string($data['status'])) {
            return in_array($data['status'], $known, true) ? $data['status'] : 'unknown';
        }

        // --- Legacy validator (no status field) ---
        if (!empty($data['error'])) {
            foreach (['Error connecting', 'Failed to validate code due to an error', 'Error parsing', 'Something went wrong', 'User already exist'] as $needle) {
                if (stripos($msg, $needle) !== false) {
                    return 'upstream_error';
                }
            }
            return 'invalid_code';
        }
        if ($msg === base64_decode('VXNlciBhbHJlYWR5IHJlZ2lzdGVyZWQu')) { // 'User already registered.'
            return 'registered';
        }
        if ($msg === 'Purchase code verified successfully!') {
            return 'registered_now';
        }
        return 'unknown';
    }

    private static function downFile(): string
    {
        return storage_path('framework/' . self::DOWN_KEY);
    }

    private static function isMarkedDown(): bool
    {
        try {
            if (Cache::has(self::DOWN_KEY)) {
                return true;
            }
        } catch (\Throwable $e) {
        }
        // File fallback: Cache driver may be unavailable (e.g. CACHE_DRIVER=none).
        $f = self::downFile();
        return @is_file($f) && (time() - (int) @filemtime($f)) < self::DOWN_TTL;
    }

    private static function markDown(): void
    {
        try {
            Cache::put(self::DOWN_KEY, 1, self::DOWN_TTL);
        } catch (\Throwable $e) {
        }
        @file_put_contents(self::downFile(), (string) time());
    }

    private static function req(string $method, string $url, ?array $post = null): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);
        if ($method === 'POST' && $post) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $r = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code === 200) ? $r : null;
    }

    private static function genuine(array $r, string $sent, array $cfg): bool
    {
        if (!empty($r['error']))
            return true;
        $fields = ['error', 'message', base64_decode('cHVyY2hhc2VfY29kZQ=='), 'token', base64_decode('dXNlcm5hbWU='), base64_decode('aXRlbV9pZA==')];
        foreach ($fields as $f) {
            if (!isset($r[$f]))
                return false;
        }
        if ($r[base64_decode('cHVyY2hhc2VfY29kZQ==')] !== $sent)
            return false;
        if ((int) $r[base64_decode('aXRlbV9pZA==')] !== $cfg['pid'])
            return false;
        return true;
    }

    private static function store(string $status, string $message, string $domain): void
    {
        try {
            $ts = now();
            ServiceHealthLog::create([
                'status' => $status,
                'response_message' => $message,
                'domain_checked' => $domain,
                'checksum' => self::sign($status, $domain, $ts->toDateTimeString()),
                'checked_at' => $ts,
            ]);
        } catch (\Throwable $e) {
        }
    }

    private static function sign(string $status, string $domain, string $ts): string
    {
        $key = substr(config('app.key'), 0, 16);
        return hash_hmac('sha256', $status . '|' . $domain . '|' . $ts, $key);
    }

    private static function verifyChecksum(ServiceHealthLog $record): bool
    {
        if (empty($record->checksum))
            return false;
        $expected = self::sign($record->status, $record->domain_checked, $record->checked_at->toDateTimeString());
        return hash_equals($expected, $record->checksum);
    }

    private static function revert(string $code, string $domain, string $un): void
    {
        try {
            $vUrl = base64_decode('aHR0cHM6Ly92YWxpZGF0b3Iud3J0ZWFtLmluL2RvbWFpbl9yZXNldC92ZXJpZnk=');
            $rUrl = base64_decode('aHR0cHM6Ly92YWxpZGF0b3Iud3J0ZWFtLmluL2RvbWFpbl9yZXNldC9yZXNldF9kb21haW4=');
            $pcKey = base64_decode('cHVyY2hhc2VfY29kZQ==');
            $unKey = base64_decode('dXNlcm5hbWU=');

            $vr = self::req('POST', $vUrl, [$unKey => $un, $pcKey => $code]);
            if (!$vr)
                return;
            $vd = json_decode($vr, true);
            if (!empty($vd['error']))
                return;

            $did = null;
            $tn = $vd['data']['table_name'] ?? null;
            foreach (($vd['data']['domains'] ?? []) as $entry) {
                if (($entry[base64_decode('ZG9tYWluX3VybA==')] ?? '') === $domain) {
                    $did = $entry['id'];
                    break;
                }
            }
            if ($did && $tn) {
                self::req('POST', $rUrl, [$pcKey => $code, $unKey => $un, base64_decode('ZG9tYWluX2lk') => $did, base64_decode('dGFibGVfbmFtZQ==') => $tn]);
            }
        } catch (\Throwable $e) {
        }
    }

    private static function purgeOld(): void
    {
        try {
            $keep = ServiceHealthLog::latest('checked_at')->take(10)->pluck('id');
            ServiceHealthLog::whereNotIn('id', $keep)->delete();
        } catch (\Throwable $e) {
        }
    }

    private static function deny(string $msg)
    {
        abort(403, $msg, ['X-License-Denied' => '1']);
    }

    public static function config(): array
    {
        return [
            'ep' => base64_decode('aHR0cHM6Ly92YWxpZGF0b3Iud3J0ZWFtLmluL2VjbGFzc2lmeV92YWxpZGF0b3I='),
            'ek' => base64_decode('QVBQU0VDUkVU'),
            'pid' => 51588129,
        ];
    }
}
