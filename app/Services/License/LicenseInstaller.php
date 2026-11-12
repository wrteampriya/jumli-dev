<?php

namespace App\Services\License;

use App\Models\PluginLicense;
use App\Services\Plugin\PluginManifest;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class LicenseInstaller
{
    public function __construct(protected LicenseVerifier $verifier)
    {
    }

    // eClassify plugins (Cashfree, PayU India) validate against the
    // validator's own per-product history endpoint — same pattern every
    // other WRTeam product uses ({slug}_validator?purchase_code=&domain_url=),
    // not the generic signed plugin-license API (that system was reverted).
    public function install(string $slug, string $purchaseCode, ?string $domain = null, string $scope = 'universal'): PluginLicense
    {
        $domain = $domain ?? LicenseDomain::current();

        $this->verify($slug, $purchaseCode, $domain);

        return $this->save($slug, $purchaseCode, $domain, $scope);
    }

    // Validator check only — writes nothing. PluginManagerController calls
    // this before touching the zip and save() only after the zip extracted,
    // so a rejected package never leaves a license row behind.
    public function verify(string $slug, string $purchaseCode, ?string $domain = null): void
    {
        $response = $this->callValidator($slug, $purchaseCode, $domain ?? LicenseDomain::current());

        if (($response['error'] ?? true) !== false) {
            throw new Exception($response['message'] ?? 'This purchase code is not valid.');
        }
    }

    // type/version come from the installed module.json, so call this after
    // extraction — a "module" license must never be stored as "plugin".
    public function save(string $slug, string $purchaseCode, ?string $domain = null, string $scope = 'universal'): PluginLicense
    {
        $domain = $domain ?? LicenseDomain::current();
        $manifest = PluginManifest::read($slug) ?? [];

        // 'revoked' is deliberately not set here — reinstalling must not
        // silently un-revoke a license killed via plugin:license:revoke.
        // Reuse an existing row for an equivalent domain (localhost <-> 127.0.0.1)
        // instead of creating a duplicate license for the same machine.
        $license = PluginLicense::forDomain($domain)->where('plugin_slug', $slug)->first()
            ?? new PluginLicense(['plugin_slug' => $slug, 'domain' => $domain]);

        $license->fill([
            'purchase_code_hash' => hash('sha256', $purchaseCode),
            'purchase_code_encrypted' => Crypt::encryptString($purchaseCode),
            'scope' => $scope,
            'type' => $manifest['type'] ?? $license->type ?? 'plugin',
            'version' => $manifest['version'] ?? $license->version,
            // Signature columns are NOT NULL but unused — this endpoint
            // doesn't sign responses.
            'signature' => '',
            'payload_b64' => null,
            'key_id' => 'v1',
            'alg' => 'ed25519',
            'issued_at' => now(),
            'expires_hint_at' => null,
            'recheck_failing_since' => null,
            'grace_expired' => false,
            'verified_at' => now(),
        ])->save();

        return $license;
    }

    protected function callValidator(string $slug, string $purchaseCode, string $domain): array
    {
        $url = config('license.validator_url') . "/plugin/" . config('license.product_key') . "/$slug";

        try {
            $response = Http::withOptions([
                'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
                'allow_redirects' => ['max' => 10],
                'version' => '1.1',
            ])->withHeaders([
                'X-Product-Key' => 'eclassify',
            ])->get($url, [
                'purchase_code' => $purchaseCode,
                'domain_url' => $domain,
            ]);

        } catch (ConnectionException $e) {
            throw new Exception('Could not reach the license validator: '.$e->getMessage());
        }

        $decoded = $response->json();

        if (!is_array($decoded)) {
            throw new Exception('License validator returned an unexpected response.');
        }

        return $decoded;
    }
}
