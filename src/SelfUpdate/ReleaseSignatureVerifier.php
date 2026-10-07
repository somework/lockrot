<?php

declare(strict_types=1);

namespace Lockrot\SelfUpdate;

use Lockrot\Exception\ConfigException;

/**
 * Verifies a release as Composer's `SelfUpdateCommand::verifyPhar()` does: the signature file is
 * `{"sha384": "<base64>"}`, an RSA signature (PKCS#1 v1.5 over SHA-384) over the archive's bytes,
 * checked with `openssl_verify()` against the public key in {@see ReleaseKey}.
 *
 * Trust starts with the first download, and a key rotation arrives in a transition release:
 * SECURITY.md#how-self-update-trusts-a-release
 *
 * @internal
 */
final class ReleaseSignatureVerifier implements SignatureVerifierInterface
{
    private string $publicKeyPem;

    public function __construct(string $publicKeyPem)
    {
        $this->publicKeyPem = $publicKeyPem;
    }

    public function verify(string $archive, string $signatureFile, string $signatureUrl): void
    {
        if (!\extension_loaded('openssl')) {
            throw new ConfigException('the openssl extension is needed to verify the release signature, and this PHP does not have it; nothing was written');
        }
        if (!\in_array('sha384', openssl_get_md_methods(), true)) {
            throw new ConfigException('the openssl extension of this PHP has no SHA-384, so the release signature cannot be checked; nothing was written');
        }
        $signature = self::signatureIn($signatureFile, $signatureUrl);
        $key = openssl_pkey_get_public($this->publicKeyPem);
        if ($key === false) {
            throw new ConfigException('the public key self-update verifies releases with cannot be loaded; download the new release by hand and verify it');
        }
        // openssl reports every kind of mismatch as 0 and an error inside openssl as -1. The error
        // is this machine's fault and is not reported as a forgery. No outside input reaches it.
        $verified = openssl_verify($archive, $signature, $key, \OPENSSL_ALGO_SHA384);
        if ($verified === -1) {
            $reason = openssl_error_string();

            throw new ConfigException('openssl could not check the release signature'.(\is_string($reason) ? ': '.$reason : '').'; nothing was written');
        }
        if ($verified !== 1) {
            throw new ConfigException(\sprintf(
                'the signature in %s does not match the downloaded archive: either the release was signed with a key this lockrot.phar does not know, or the download is not the published archive; nothing was written',
                $signatureUrl
            ));
        }
    }

    /**
     * The DER is the base64 between the armour lines, so the release workflow computes the same
     * value without openssl. base64_decode() skips the line breaks of the block even in strict mode.
     * lockrot reads only a `PUBLIC KEY` block: an `RSA PUBLIC KEY` block holds the same key in
     * PKCS#1, whose hash differs and mismatches every description in silence. With openssl present
     * the key must also load, so a damaged key is an error and not a fingerprint that no release
     * names. Without openssl, `--check` still gets its fingerprint.
     */
    public function keyFingerprint(): string
    {
        $pattern = '/^\s*-----BEGIN PUBLIC KEY-----([^-]*)-----END PUBLIC KEY-----\s*$/';
        $der = preg_match($pattern, $this->publicKeyPem, $matches) === 1 ? base64_decode($matches[1], true) : false;
        if ($der === false || $der === '') {
            throw new ConfigException('the public key self-update verifies releases with is not a PEM "PUBLIC KEY" block, so it has no fingerprint; download the new release by hand and verify it');
        }
        if (\extension_loaded('openssl') && openssl_pkey_get_public($this->publicKeyPem) === false) {
            throw new ConfigException('the public key self-update verifies releases with cannot be loaded; download the new release by hand and verify it');
        }

        return 'sha256:'.hash('sha256', $der);
    }

    /**
     * Accepts only a JSON object whose `sha384` member is strict base64: any other file, such as an
     * HTML error page, gets a message and never reaches the verifier.
     */
    private static function signatureIn(string $signatureFile, string $signatureUrl): string
    {
        $decoded = json_decode($signatureFile, true);
        $encoded = \is_array($decoded) ? ($decoded['sha384'] ?? null) : null;
        $signature = \is_string($encoded) && $encoded !== '' ? base64_decode($encoded, true) : false;
        if ($signature === false || $signature === '') {
            throw new ConfigException($signatureUrl.' is not a lockrot release signature ({"sha384": "<base64>"} expected); nothing was written');
        }

        return $signature;
    }
}
