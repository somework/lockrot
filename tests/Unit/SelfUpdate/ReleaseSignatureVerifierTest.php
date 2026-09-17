<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\SelfUpdate;

use Lockrot\Exception\ConfigException;
use Lockrot\SelfUpdate\ReleaseSignatureVerifier;
use Lockrot\Tests\Support\SigningKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseSignatureVerifierTest extends TestCase
{
    private const URL = 'https://github.com/somework/lockrot/releases/download/v0.6.0/lockrot.phar.sig';
    private const ARCHIVE = 'the bytes of a lockrot.phar';

    private function verifier(?string $pem = null): ReleaseSignatureVerifier
    {
        return new ReleaseSignatureVerifier($pem ?? SigningKeys::releasePublicPem());
    }

    public function testTheReleaseKeysSignatureOverTheArchiveIsAccepted(): void
    {
        $this->verifier()->verify(self::ARCHIVE, SigningKeys::releaseSignatureFile(self::ARCHIVE), self::URL);
        $this->addToAssertionCount(1);
    }

    /**
     * The file format is Composer's, byte for byte: a JSON object with one `sha384` member, the
     * base64 of what `openssl dgst -sha384 -sign` writes. Pinned here so the release workflow and
     * the verifier cannot drift apart unnoticed.
     */
    public function testTheSignatureFileIsComposersFormat(): void
    {
        $file = SigningKeys::releaseSignatureFile(self::ARCHIVE);
        $decoded = json_decode($file, true);

        self::assertIsArray($decoded);
        self::assertSame(['sha384'], array_keys($decoded));
        self::assertIsString($decoded['sha384']);
        self::assertSame(SigningKeys::releaseSignatureBytes(self::ARCHIVE), base64_decode($decoded['sha384'], true));
        self::assertSame(1, openssl_verify(
            self::ARCHIVE,
            SigningKeys::releaseSignatureBytes(self::ARCHIVE),
            SigningKeys::releasePublicPem(),
            \OPENSSL_ALGO_SHA384
        ));
    }

    public function testASignatureByAnotherKeyIsRefusedNamingTheUrl(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('the signature in '.self::URL.' does not match the downloaded archive');
        $this->verifier()->verify(self::ARCHIVE, SigningKeys::otherSignatureFile(self::ARCHIVE), self::URL);
    }

    public function testASignatureOverOtherBytesIsRefused(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('does not match the downloaded archive');
        $this->verifier()->verify(self::ARCHIVE.' plus one byte', SigningKeys::releaseSignatureFile(self::ARCHIVE), self::URL);
    }

    /** Padding matters: the same key and bytes under another digest or padding are not a match. */
    public function testASignatureUnderAnotherDigestIsRefused(): void
    {
        $key = openssl_pkey_get_private((string) file_get_contents(__DIR__.'/../../fixtures/signing/release-key.pem'));
        self::assertNotFalse($key);
        $signature = '';
        self::assertTrue(openssl_sign(self::ARCHIVE, $signature, $key, \OPENSSL_ALGO_SHA256));
        self::assertIsString($signature);
        $file = json_encode(['sha384' => base64_encode($signature)], \JSON_THROW_ON_ERROR);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('does not match the downloaded archive');
        $this->verifier()->verify(self::ARCHIVE, $file, self::URL);
    }

    /** @return iterable<string, array{0: string}> */
    public static function notASignatureFile(): iterable
    {
        yield 'html error page' => ['<html><body>404</body></html>'];
        yield 'empty' => [''];
        yield 'json but not an object' => ['"sha384"'];
        yield 'object without sha384' => ['{"sha256": "abc="}'];
        yield 'sha384 not a string' => ['{"sha384": 42}'];
        yield 'sha384 empty' => ['{"sha384": ""}'];
        yield 'sha384 not base64' => ['{"sha384": "not base64!"}'];
        yield 'sha384 base64 of nothing' => ['{"sha384": "===="}'];
        yield 'truncated json' => ['{"sha384": "YWJj'];
    }

    /** @dataProvider notASignatureFile */
    #[DataProvider('notASignatureFile')]
    public function testAFileThatIsNotASignatureIsRefusedBeforeAnyCryptography(string $file): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage(self::URL.' is not a lockrot release signature');
        $this->verifier()->verify(self::ARCHIVE, $file, self::URL);
    }

    /**
     * The archive carries the key; a build whose key cannot be loaded cannot verify anything, and
     * says so rather than reporting the release as forged.
     */
    public function testAKeyThatCannotBeLoadedIsReportedAsTheBuildsOwnFault(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('the release key inside this lockrot.phar cannot be loaded');
        $this->verifier("-----BEGIN PUBLIC KEY-----\nnot a key\n-----END PUBLIC KEY-----\n")
            ->verify(self::ARCHIVE, SigningKeys::releaseSignatureFile(self::ARCHIVE), self::URL);
    }

    /** The signature file is read before the key: garbage in is reported as garbage, not as a key fault. */
    public function testTheFileIsReadBeforeTheKeyIsLoaded(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('is not a lockrot release signature');
        $this->verifier('not a key')->verify(self::ARCHIVE, 'not json', self::URL);
    }
}
