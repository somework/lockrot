#!/usr/bin/env php
<?php

declare(strict_types=1);

// The self-update signature file, for the release workflow, with four commands:
// - wrap: the raw signature bytes of `openssl dgst -sha384 -sign` to the `{"sha384": "<base64>"}` file
//   that lockrot.phar self-update reads.
// - unwrap: the reverse, so `openssl dgst -verify` can check the signature.
// - verify: the signature against the key of the previous release, see verify().
// - describe: the lockrot.phar.meta.json of the release, see describe().

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

/**
 * Checks the signature with the key that the previous release carries. Every archive in the field runs
 * this check, so a key that those archives lack fails the release build, not the users. A rotation in
 * one step (new key in ReleaseKey::PEM, the .pub and the secret at once) fails too: no archive in the
 * field could follow it. Returns the message for stdout. A build whose key (ReleaseKey::PEM, or
 * `$carriedKey`) differs from that key is the transition release of a rotation, and the next release
 * must be signed with the new key. Exits 1 on a signature that the archives in the field cannot verify.
 */
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

/**
 * The content of the release's lockrot.phar.meta.json. Self-update reads it to choose a release before
 * it downloads the archive. It holds the lowest PHP of the archive and the fingerprint of the public
 * key. In the workflow, that key derives from the private key that signed the release. The lowest PHP
 * is config.platform.php of the PHAR's composer.json, spelled `major.minor.patch` (the only spelling
 * that an archive reads). It must equal the lowest PHP that require.php allows there and in the root
 * composer.json.
 */
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
