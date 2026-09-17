<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\SelfUpdate;

use Lockrot\SelfUpdate\ReleaseKey;
use Lockrot\Tests\Support\SigningKeys;
use PHPUnit\Framework\TestCase;

final class ReleaseKeyTest extends TestCase
{
    /** The key inside the archive is the one published in the repository root, byte for byte. */
    public function testTheBuiltInKeyIsThePublishedOne(): void
    {
        // The file ends in a newline, as PEM files do; the nowdoc in ReleaseKey does not carry one.
        self::assertSame(file_get_contents(__DIR__.'/../../../lockrot-selfupdate-key.pub'), ReleaseKey::PEM."\n");
    }

    public function testTheBuiltInKeyIsAnRsa4096PublicKey(): void
    {
        $key = openssl_pkey_get_public(ReleaseKey::PEM);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertSame(\OPENSSL_KEYTYPE_RSA, $details['type']);
        self::assertSame(4096, $details['bits']);
    }

    /** The test fixture keys are not, and must never become, the release key. */
    public function testTheBuiltInKeyIsNotATestFixture(): void
    {
        self::assertNotSame(SigningKeys::releasePublicPem(), ReleaseKey::PEM);
        self::assertNotSame(SigningKeys::otherPublicPem(), ReleaseKey::PEM);
    }
}
