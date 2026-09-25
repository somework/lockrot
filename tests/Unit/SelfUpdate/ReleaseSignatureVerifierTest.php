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
    private const URL = 'https://github.com/somework/lockrot/releases/download/v0.6.0/lockrot.phar.sig.json';
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
        $pem = __DIR__.'/../../fixtures/signing/release-key.pem';
        $key = openssl_pkey_get_private((string) file_get_contents($pem));
        self::assertNotFalse($key);
        $signature = '';
        self::assertTrue(openssl_sign(self::ARCHIVE, $signature, $key, \OPENSSL_ALGO_SHA256));
        // Through the same helper SigningKeys signs with, and for the same reason: PHPStan reads an
        // assertIsString() on this variable as always true and its own inference reads it as mixed.
        $bytes = SigningKeys::signatureBytes($signature, $pem);
        $file = json_encode(['sha384' => base64_encode($bytes)], \JSON_THROW_ON_ERROR);

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
        $this->expectExceptionMessage('the public key self-update verifies releases with cannot be loaded');
        $this->verifier("-----BEGIN PUBLIC KEY-----\nnot a key\n-----END PUBLIC KEY-----\n")
            ->verify(self::ARCHIVE, SigningKeys::releaseSignatureFile(self::ARCHIVE), self::URL);
    }

    /**
     * `sha256:` and the SHA-256 of the DER SubjectPublicKeyInfo, which is what
     * `openssl pkey -pubin -outform DER | sha256sum` prints — the two pinned values were taken with
     * that command. The DER is also read back through openssl here, so the fingerprint is of the key
     * openssl loads, not of whatever text sits between the armour lines.
     */
    public function testTheFingerprintIsTheSha256OfTheDerPublicKey(): void
    {
        $pinned = [
            'sha256:3c497788168deae9ea365ea31ff4d0ea702e357149a925ce843242e99c906e93' => SigningKeys::releasePublicPem(),
            'sha256:a62913297acdb3f80692eae73426ffa992b476f360ce0b7aaa984da0a5b122b4' => SigningKeys::otherPublicPem(),
        ];
        foreach ($pinned as $fingerprint => $pem) {
            $key = openssl_pkey_get_public($pem);
            self::assertNotFalse($key);
            $details = openssl_pkey_get_details($key);
            self::assertIsArray($details);
            self::assertIsString($details['key']);
            $body = preg_replace('/-----[A-Z ]+-----|\s/', '', $details['key']);
            $der = base64_decode((string) $body, true);
            self::assertIsString($der);

            self::assertSame('sha256:'.hash('sha256', $der), $this->verifier($pem)->keyFingerprint());
            self::assertSame($fingerprint, $this->verifier($pem)->keyFingerprint());
        }
    }

    /** A key file saved on Windows, or re-wrapped at another width, is still the same key. */
    public function testLineEndingsAndWrappingDoNotChangeTheFingerprint(): void
    {
        $pem = SigningKeys::releasePublicPem();
        $body = (string) preg_replace('/-----[A-Z ]+-----|\s/', '', $pem);
        $rewrapped = "-----BEGIN PUBLIC KEY-----\r\n".chunk_split($body, 76, "\r\n")."-----END PUBLIC KEY-----\r\n";

        self::assertSame($this->verifier($pem)->keyFingerprint(), $this->verifier(str_replace("\n", "\r\n", $pem))->keyFingerprint());
        self::assertSame($this->verifier($pem)->keyFingerprint(), $this->verifier($rewrapped)->keyFingerprint());
        self::assertSame($this->verifier($pem)->keyFingerprint(), $this->verifier(rtrim($pem))->keyFingerprint());
    }

    /** @return iterable<string, array{0: string}> */
    public static function notAPublicKeyPem(): iterable
    {
        $pem = SigningKeys::releasePublicPem();
        $body = (string) preg_replace('/-----[A-Z ]+-----|\s/', '', $pem);

        yield 'not pem at all' => ['not a key'];
        // PKCS#1 carries the same key in another structure, whose hash is not the DER
        // SubjectPublicKeyInfo fingerprint a description names: a silent mismatch forever after.
        yield 'an RSA PUBLIC KEY block' => ["-----BEGIN RSA PUBLIC KEY-----\n".$body."\n-----END RSA PUBLIC KEY-----\n"];
        yield 'a private key block' => ["-----BEGIN PRIVATE KEY-----\n".$body."\n-----END PRIVATE KEY-----\n"];
        yield 'text in front of the block' => ["a comment\n".$pem];
        yield 'text after the block' => [$pem."a comment\n"];
        yield 'a body that is not base64' => ["-----BEGIN PUBLIC KEY-----\nnot base64!\n-----END PUBLIC KEY-----\n"];
        yield 'padding in the middle of the body' => ["-----BEGIN PUBLIC KEY-----\nab=cd\n-----END PUBLIC KEY-----\n"];
        yield 'an empty body' => ["-----BEGIN PUBLIC KEY-----\n-----END PUBLIC KEY-----\n"];
    }

    /** @dataProvider notAPublicKeyPem */
    #[DataProvider('notAPublicKeyPem')]
    public function testAKeyThatIsNotAPublicKeyPemHasNoFingerprint(string $pem): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('the public key self-update verifies releases with is not a PEM "PUBLIC KEY" block, so it has no fingerprint');
        $this->verifier($pem)->keyFingerprint();
    }

    /**
     * A block that is well-formed base64 but not a key openssl can load has no fingerprint either:
     * one that hashed would match no release's description, and every release would be passed over
     * as signed with another key instead of this being the error verify() reports for the same key.
     */
    public function testAKeyOpensslCannotLoadHasNoFingerprint(): void
    {
        $lines = explode("\n", SigningKeys::releasePublicPem());
        unset($lines[3]);
        $damaged = implode("\n", $lines);
        self::assertFalse(openssl_pkey_get_public($damaged), 'the fixture must be a key openssl refuses');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('the public key self-update verifies releases with cannot be loaded; download the new release by hand and verify it');
        $this->verifier($damaged)->keyFingerprint();
    }

    /** The signature file is read before the key: garbage in is reported as garbage, not as a key fault. */
    public function testTheFileIsReadBeforeTheKeyIsLoaded(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('is not a lockrot release signature');
        $this->verifier('not a key')->verify(self::ARCHIVE, 'not json', self::URL);
    }
}
