<?php

declare(strict_types=1);

namespace Lockrot\SelfUpdate;

use Lockrot\Exception\ConfigException;

/** @internal */
interface SignatureVerifierInterface
{
    /**
     * Checks that $signatureFile — the body of the release's `lockrot.phar.sig.json` — is the release
     * key's signature over exactly $archive.
     *
     * @param string $archive       the downloaded archive, byte for byte
     * @param string $signatureFile the downloaded signature file
     * @param string $signatureUrl  where the signature came from, for the message
     *
     * @throws ConfigException when the signature cannot be read, cannot be checked, or does not
     *                         match — every one of which means the archive is not installed
     */
    public function verify(string $archive, string $signatureFile, string $signatureUrl): void;

    /**
     * `sha256:` followed by the hex SHA-256 of the DER public key this verifier trusts — the value
     * `openssl pkey -pubin -outform DER | sha256sum` prints, and the one a release's
     * `lockrot.phar.meta.json` names for the key that signed it. {@see ReleaseLocator} passes over a
     * release whose key is not this one.
     *
     * @throws ConfigException when the key is not one a fingerprint can be taken of
     */
    public function keyFingerprint(): string;
}
