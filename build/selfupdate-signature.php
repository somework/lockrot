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
//   build/selfupdate-signature.php wrap   build/lockrot.phar.sig.bin > build/lockrot.phar.sig
//   build/selfupdate-signature.php unwrap build/lockrot.phar.sig     > build/lockrot.phar.sig.bin
//   build/selfupdate-signature.php verify build/lockrot.phar build/lockrot.phar.sig

require __DIR__.'/../vendor/autoload.php';

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
    default:
        fwrite(STDERR, "usage: selfupdate-signature.php wrap <sig.bin> | unwrap <sig> | verify <phar> <sig>\n");
        exit(2);
}
