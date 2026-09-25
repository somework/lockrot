#!/usr/bin/env php
<?php

declare(strict_types=1);

// The self-update signature file, for the release workflow: `wrap` turns the raw signature bytes
// `openssl dgst -sha384 -sign` wrote into the `{"sha384": "<base64>"}` file lockrot.phar self-update
// reads, and `unwrap` does the reverse so `openssl dgst -verify` can check it.
//
// `verify` runs the archive's own ReleaseSignatureVerifier with the key the *previous* release
// carries — the check every archive in the field will run on this release — so a signing key those
// archives do not have fails the release build, not the users. That includes a rotation done in
// one step (new key in ReleaseKey::PEM, the .pub and the secret at once): no archive in the field
// could follow it. It then compares that key with the one this build carries (ReleaseKey::PEM, or
// the fourth argument): the same key is an ordinary release; another one makes this the transition
// release of a rotation — signed with the old key, carrying the new one — which is said on stdout,
// and from which the next release must be signed with the new key, or fail this same check.
//
// `describe` prints the release's lockrot.phar.meta.json, which self-update chooses a release by
// without downloading its archive: the lowest PHP the archive runs on (config.platform.php of the
// PHAR's composer.json, spelled `major.minor.patch` — the only spelling an archive reads — and the
// lowest PHP its and the root composer.json's require.php allow) and the fingerprint of the public
// key given — in the workflow, the key derived from the private key the release was actually
// signed with.
//
//   build/selfupdate-signature.php wrap     build/lockrot.phar.sig.bin > build/lockrot.phar.sig.json
//   build/selfupdate-signature.php unwrap   build/lockrot.phar.sig.json     > build/lockrot.phar.sig.bin
//   build/selfupdate-signature.php verify   build/lockrot.phar build/lockrot.phar.sig.json build/previous-selfupdate-key.pub [carried.pub]
//   build/selfupdate-signature.php describe build/phar/composer.json build/selfupdate-signer.pub > build/lockrot.phar.meta.json

require __DIR__.'/../vendor/autoload.php';

use Composer\Semver\VersionParser;
use Lockrot\Exception\ConfigException;
use Lockrot\SelfUpdate\ReleaseDescription;
use Lockrot\SelfUpdate\ReleaseKey;
use Lockrot\SelfUpdate\ReleaseSignatureVerifier;

function readFileOrFail(string $path): string
{
    $contents = @file_get_contents($path);
    if ($contents === false || $contents === '') {
        fwrite(STDERR, "cannot read $path, or it is empty\n");
        exit(1);
    }

    return $contents;
}

function fail(string $message): void
{
    fwrite(STDERR, $message."\n");
    exit(1);
}

/** @return array<mixed> */
function manifest(string $path): array
{
    $decoded = json_decode(readFileOrFail($path), true);
    if (!is_array($decoded)) {
        fail("$path is not a JSON object");
    }

    return $decoded;
}

/** The floor $path's require.php allows, normalised (7.4.0.0 for "^7.4 || ^8.0"). */
function declaredFloor(string $path): string
{
    $require = manifest($path)['require'] ?? null;
    $php = is_array($require) ? ($require['php'] ?? null) : null;
    if (!is_string($php)) {
        fail("$path has no require.php");
    }

    return preg_replace('/-dev$/', '', (new VersionParser())->parseConstraints($php)->getLowerBound()->getVersion());
}

function fingerprintOf(string $publicKey): string
{
    try {
        return (new ReleaseSignatureVerifier(readFileOrFail($publicKey)))->keyFingerprint();
    } catch (ConfigException $e) {
        fail("$publicKey: ".$e->getMessage());
    }
}

/** What `verify` reports; see the top of this file. Exits 1 on a signature the field cannot verify. */
function verify(string $phar, string $signatureFile, string $previousKey, ?string $carriedKey): string
{
    try {
        (new ReleaseSignatureVerifier(readFileOrFail($previousKey)))->verify(readFileOrFail($phar), readFileOrFail($signatureFile), $signatureFile);
    } catch (ConfigException $e) {
        fail('the key of the previous release does not verify this one, so no archive in the field could install it: '.$e->getMessage()
            .'. A rotation needs a transition release signed with the old key and carrying the new one (SECURITY.md).');
    }
    $previous = fingerprintOf($previousKey);
    $carried = $carriedKey === null ? (new ReleaseSignatureVerifier(ReleaseKey::PEM))->keyFingerprint() : fingerprintOf($carriedKey);
    if ($carried === $previous) {
        return "self-update signature verified with the key of the previous release, which this archive carries too ($previous)\n";
    }

    return "self-update signature verified with the key of the previous release ($previous); this archive carries another ($carried), "
        ."so this is the transition release of a rotation, and the next release must be signed with the new key\n";
}

function describe(string $composerJson, string $publicKey): string
{
    $config = manifest($composerJson)['config'] ?? null;
    $platform = is_array($config) && is_array($config['platform'] ?? null) ? ($config['platform']['php'] ?? null) : null;
    if (!is_string($platform)) {
        fail("$composerJson has no config.platform.php");
    }
    if (preg_match(ReleaseDescription::PHP_FLOOR_PATTERN, $platform) !== 1) {
        fail("config.platform.php $platform is not spelled major.minor.patch, the only floor an archive reads");
    }
    $normalized = (new VersionParser())->normalize($platform);
    foreach ([$composerJson, __DIR__.'/../composer.json'] as $manifest) {
        $floor = declaredFloor($manifest);
        if ($floor !== $normalized) {
            fail("config.platform.php $platform is not the lowest PHP $manifest allows ($floor); the release would describe a floor its archive does not have");
        }
    }

    return json_encode(['php' => $platform, 'selfupdate-key' => fingerprintOf($publicKey)], JSON_THROW_ON_ERROR)."\n";
}

$command = $argv[1] ?? '';
switch ($command) {
    case 'wrap':
        echo json_encode(['sha384' => base64_encode(readFileOrFail($argv[2] ?? ''))], JSON_THROW_ON_ERROR), "\n";
        exit(0);
    case 'unwrap':
        $decoded = json_decode(readFileOrFail($argv[2] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        $signature = is_array($decoded) && is_string($decoded['sha384'] ?? null) ? base64_decode($decoded['sha384'], true) : false;
        if ($signature === false || $signature === '') {
            fwrite(STDERR, "not a signature file\n");
            exit(1);
        }
        echo $signature;
        exit(0);
    case 'verify':
        echo verify($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '', $argv[5] ?? null);
        exit(0);
    case 'describe':
        echo describe($argv[2] ?? '', $argv[3] ?? '');
        exit(0);
    default:
        fwrite(STDERR, "usage: selfupdate-signature.php wrap <sig.bin> | unwrap <sig> | verify <phar> <sig> <previous release's key> [<carried key>] | describe <composer.json> <public key>\n");
        exit(2);
}
