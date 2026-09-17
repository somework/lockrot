<?php

declare(strict_types=1);

namespace Lockrot\SelfUpdate;

use Lockrot\Exception\ConfigException;

/**
 * Verifies a release the way Composer's own self-update verifies composer.phar
 * ({@see \Composer\Command\SelfUpdateCommand::verifyPhar()}): the signature file is a JSON object
 * `{"sha384": "<base64>"}` holding an RSA signature (PKCS#1 v1.5 over SHA-384, what
 * `openssl dgst -sha384 -sign` produces) over the archive's bytes, checked with `openssl_verify()`
 * against a public key that ships inside the running archive ({@see ReleaseKey}).
 *
 * The check needs nothing from the machine: no `gpg`, no keyring, no network beyond the two
 * downloads. It does need ext-openssl, which every PHP that can download over https has — and the
 * download that precedes this check is over https.
 *
 * The key is the one the archive was built with, so trust starts with the first download: verify
 * that one by hand (`lockrot.phar.asc` with the GPG key, or the attestation), and every self-update
 * after it is checked against the key that download carried. A rotated key reaches an old archive
 * the same way — by updating to a release signed with both.
 */
final class ReleaseSignatureVerifier implements SignatureVerifierInterface
{
    private string $publicKeyPem;

    /** @param string $publicKeyPem a `-----BEGIN PUBLIC KEY-----` block */
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
        // 1 is a match, 0 a mismatch (a wrong key, other bytes, a signature of the wrong length —
        // openssl reports them all as 0), -1 an error inside openssl itself. The last is this
        // machine's fault, not the release's, and is reported as such rather than as a forgery; no
        // input from outside reaches it, so no test can either.
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
     * The raw signature bytes out of the `.sig` file. Only the one shape is accepted — a JSON
     * object whose `sha384` member is base64 in the strict alphabet — so a truncated download, an
     * HTML error page or a file signed under another scheme is a readable message, never bytes
     * handed to the verifier.
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
