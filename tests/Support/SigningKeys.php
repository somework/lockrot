<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

/**
 * The test-only RSA key pairs under tests/fixtures/signing, and the release signature file format
 * built from them — the same `{"sha384": "<base64>"}` the release workflow publishes as
 * `lockrot.phar.sig.json` and {@see \Lockrot\SelfUpdate\ReleaseSignatureVerifier} reads.
 *
 * `release` stands in for the lockrot release key; `other` is a key of the same shape that is not
 * it. Both are checked in on purpose: generating keys in a test is slow on some runners and needs
 * an openssl.cnf the php Docker images do not always have, and a key that only ever signs test
 * strings protects nothing.
 */
final class SigningKeys
{
    private const DIR = __DIR__.'/../fixtures/signing';

    public static function releasePublicPem(): string
    {
        return self::read('release-key.pub');
    }

    public static function otherPublicPem(): string
    {
        return self::read('other-key.pub');
    }

    /** The `.sig.json` file the release key would publish for $archive. */
    public static function releaseSignatureFile(string $archive): string
    {
        return self::signatureFile($archive, 'release-key.pem');
    }

    /** The `.sig.json` file a key that is not the release key would produce for $archive. */
    public static function otherSignatureFile(string $archive): string
    {
        return self::signatureFile($archive, 'other-key.pem');
    }

    /** Raw signature bytes, before the JSON and base64 the file format wraps them in. */
    public static function releaseSignatureBytes(string $archive): string
    {
        return self::sign($archive, 'release-key.pem');
    }

    private static function signatureFile(string $archive, string $keyFile): string
    {
        return json_encode(['sha384' => base64_encode(self::sign($archive, $keyFile))], \JSON_THROW_ON_ERROR)."\n";
    }

    private static function sign(string $archive, string $keyFile): string
    {
        $key = openssl_pkey_get_private(self::read($keyFile));
        if ($key === false) {
            throw new \RuntimeException('cannot load '.$keyFile);
        }
        $signature = '';
        if (!openssl_sign($archive, $signature, $key, \OPENSSL_ALGO_SHA384) || !\is_string($signature)) {
            throw new \RuntimeException('cannot sign with '.$keyFile);
        }

        return $signature;
    }

    private static function read(string $name): string
    {
        $pem = file_get_contents(self::DIR.'/'.$name);
        if ($pem === false) {
            throw new \RuntimeException('cannot read '.$name);
        }

        return $pem;
    }
}
