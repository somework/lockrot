#!/usr/bin/env php
<?php

declare(strict_types=1);

// The self-update signature file, for the release workflow: `wrap` turns the raw signature bytes
// `openssl dgst -sha384 -sign` wrote into the `{"sha384": "<base64>"}` file lockrot.phar self-update
// reads, `unwrap` does the reverse so `openssl dgst -verify` can check it, and `verify` runs the
// archive's own ReleaseSignatureVerifier against the key built into the source tree — the check a
// user's self-update will run, so a signing key that is not the one in ReleaseKey::PEM fails the
// release build, not the user.
//
// `describe` prints the release's lockrot.phar.meta.json, which self-update chooses a release by
// without downloading its archive: the lowest PHP the archive runs on (config.platform.php of the
// PHAR's composer.json, which must also be the lowest PHP its and the root composer.json's
// require.php allow) and the fingerprint of the public key given — in the workflow, the key derived
// from the private key the release was actually signed with.
//
//   build/selfupdate-signature.php wrap     build/lockrot.phar.sig.bin > build/lockrot.phar.sig.json
//   build/selfupdate-signature.php unwrap   build/lockrot.phar.sig.json     > build/lockrot.phar.sig.bin
//   build/selfupdate-signature.php verify   build/lockrot.phar build/lockrot.phar.sig.json
//   build/selfupdate-signature.php describe build/phar/composer.json build/selfupdate-signer.pub > build/lockrot.phar.meta.json

require __DIR__.'/../vendor/autoload.php';

use Composer\Semver\VersionParser;
use Lockrot\Exception\ConfigException;
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

function describe(string $composerJson, string $publicKey): string
{
    $config = manifest($composerJson)['config'] ?? null;
    $platform = is_array($config) && is_array($config['platform'] ?? null) ? ($config['platform']['php'] ?? null) : null;
    if (!is_string($platform)) {
        fail("$composerJson has no config.platform.php");
    }
    $normalized = (new VersionParser())->normalize($platform);
    foreach ([$composerJson, __DIR__.'/../composer.json'] as $manifest) {
        $floor = declaredFloor($manifest);
        if ($floor !== $normalized) {
            fail("config.platform.php $platform is not the lowest PHP $manifest allows ($floor); the release would describe a floor its archive does not have");
        }
    }
    try {
        $fingerprint = (new ReleaseSignatureVerifier(readFileOrFail($publicKey)))->keyFingerprint();
    } catch (ConfigException $e) {
        fail("$publicKey: ".$e->getMessage());
    }

    return json_encode(['php' => $platform, 'selfupdate-key' => $fingerprint], JSON_THROW_ON_ERROR)."\n";
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
        try {
            (new ReleaseSignatureVerifier(ReleaseKey::PEM))->verify(readFileOrFail($argv[2] ?? ''), readFileOrFail($argv[3] ?? ''), $argv[3] ?? '');
        } catch (ConfigException $e) {
            fwrite(STDERR, $e->getMessage()."\n");
            exit(1);
        }
        echo "self-update signature verified with the built-in key\n";
        exit(0);
    case 'describe':
        echo describe($argv[2] ?? '', $argv[3] ?? '');
        exit(0);
    default:
        fwrite(STDERR, "usage: selfupdate-signature.php wrap <sig.bin> | unwrap <sig> | verify <phar> <sig> | describe <composer.json> <public key>\n");
        exit(2);
}
