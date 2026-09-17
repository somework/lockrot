<?php

declare(strict_types=1);

namespace Lockrot\SelfUpdate;

use Lockrot\Exception\ConfigException;

interface SignatureVerifierInterface
{
    /**
     * Checks that $signatureFile — the body of the release's `lockrot.phar.sig` — is the release
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
}
