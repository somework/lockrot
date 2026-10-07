<?php

declare(strict_types=1);

namespace Lockrot\SelfUpdate;

use Lockrot\Exception\ConfigException;

/** @internal */
interface SignatureVerifierInterface
{
    /**
     * @param string $archive       the bytes of the downloaded archive, not a path
     * @param string $signatureFile the body of the release's `lockrot.phar.sig.json`
     * @param string $signatureUrl  where the signature came from, for the message
     *
     * @throws ConfigException when the signature cannot be read or checked, or does not match the
     *                         archive: the caller installs nothing
     */
    public function verify(string $archive, string $signatureFile, string $signatureUrl): void;

    /**
     * `sha256:` and the hex SHA-256 of the DER public key, the value that a release's
     * `lockrot.phar.meta.json` names for its signing key (SECURITY.md#verifying-a-downloaded-phar).
     *
     * @throws ConfigException when the key has no fingerprint or {@see verify()} cannot load it:
     *                         otherwise every release is passed over for a key no release names
     */
    public function keyFingerprint(): string;
}
