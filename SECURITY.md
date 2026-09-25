# Security policy

## Supported versions

The latest minor release is supported. Fixes go into a new patch release on that minor; there are
no backports to earlier ones.

## Reporting a security issue

Use GitHub's private vulnerability reporting:
[open a report](https://github.com/somework/lockrot/security/advisories/new). If that form is not
available to you, email i.pinchuk.work@gmail.com with `lockrot` in the subject.

Please do not open a public issue for a suspected security issue. Expect a first reply within seven
days.

## Verifying a downloaded PHAR

Every release publishes `lockrot.phar.sha256` next to `lockrot.phar`. Fetch both from
`github.com` — the checksum is only worth as much as the host it comes from, so keep it on the same
host as the release itself:

    curl -fsSL -O https://github.com/somework/lockrot/releases/latest/download/lockrot.phar
    curl -fsSL -O https://github.com/somework/lockrot/releases/latest/download/lockrot.phar.sha256
    sha256sum -c lockrot.phar.sha256

`php lockrot.phar self-update` performs the same check automatically, and from 0.6.0 on a second
one: every release also publishes `lockrot.phar.sig.json`, an RSA signature (PKCS#1 v1.5 over SHA-384,
in Composer's `{"sha384": "<base64>"}` file format) by the lockrot self-update key, and the
archive verifies it with `openssl_verify()` against the public key built into itself
(`Lockrot\SelfUpdate\ReleaseKey`, published as `lockrot-selfupdate-key.pub` in this repository).
A download whose checksum or signature does not match, or that the PHP runtime cannot open, is not
installed and the running file is left in place. Trust starts with the first download: verify that
one by hand with the GPG signature or the attestation below, and every self-update after it is
checked against the key that download carried. The archive running 0.5.0 still checks the checksum
only when it updates to 0.6.0; from 0.6.0 on the signature is checked.

The self-update key is RSA 4096 and separate from the GPG release key, the way Composer keeps its
self-update keys apart from its maintainers' keys: the archive cannot verify OpenPGP without `gpg`
on the machine, and `openssl_verify()` is in every PHP that can download over https. The private
half is held by the release workflow (`SELFUPDATE_PRIVATE_KEY`, `SELFUPDATE_PASSPHRASE`); the
workflow verifies every signature it makes before it publishes, with openssl and through the
archive's own verifier, against the key the previous release carries (see below). Its fingerprint — the SHA-256 of the DER public key,
`openssl pkey -pubin -in lockrot-selfupdate-key.pub -outform DER | sha256sum` — is
`sha256:ec3ca71b1a3ced86f871b89cff7973b58454e5694136683680b72b18070a8f87`.

Every release from 0.13.0 on also publishes `lockrot.phar.meta.json`, which names the fingerprint
of the key that actually signed it, derived by the workflow from the private key it signed with.
An archive from 0.13.0 on passes over a release signed with a key it does not carry, and that is
how a planned rotation reaches it. The new key ships in a transition release signed with the old
key: an archive in the field skips the releases after it, whose description names the new key,
installs the transition release, and from there verifies with the new key. The changelog of the
transition release names the new key's fingerprint.

The release workflow verifies every self-update signature against the key the previous release
carries (`lockrot-selfupdate-key.pub` at the previous `v*` tag) — the key every archive in the field
will verify it with — and the description must name that key. A rotation that skips its transition
release, replacing the key in the source tree and the secret at once, therefore fails the build. The
transition release needs no exception: it is built with the old key still as
`SELFUPDATE_PRIVATE_KEY`, while the source tree already carries the new one, so it passes, and the
workflow says it is a transition release. The secret is replaced with the new key right after it,
and the same check then holds the next release to the new key.

An archive that finds newer releases only under a key it does not carry, and no release it can
install that carries that key — the transition release missing, pulled, or needing a newer PHP — is
stranded: `self-update` and `self-update --check` exit 2 and say so, and the archive has to be
replaced by hand ([Reinstalling by hand](https://lockrot.dev/phar/#reinstalling-by-hand)).

A transition release is for a planned rotation only. After a compromise the old key cannot vouch
for anything, a transition release it would sign included: every release signed with it is pulled,
and every archive that carries it — 0.13 and later just as much as 0.12 and older — has to be
replaced by hand with a download verified with the GPG signature or the attestation below. The
advisory and the changelog name the new key's fingerprint. Archives up to 0.12 read only the newest
release, so after any rotation their `self-update` refuses the new release as a signature mismatch
and leaves itself in place — replace such an archive by hand once.

Every release from 0.5.0 on is also signed with the lockrot release key — `lockrot.phar.asc` next
to the PHAR — and attested by GitHub:

    gpg --keyserver hkps://keys.openpgp.org --recv-keys 39ECC3F64AE8D06A9A63FD99AB6F7F52AE513141
    gpg --verify lockrot.phar.asc lockrot.phar
    gh attestation verify lockrot.phar --repo somework/lockrot

The key's fingerprint is `39EC C3F6 4AE8 D06A 9A63 FD99 AB6F 7F52 AE51 3141`
(`lockrot release signing <i.pinchuk.work@gmail.com>`); its public half is `lockrot-release-key.asc`
in this repository. Only a signing subkey is held by the release workflow, and it is rotated before
it expires; the primary key and its revocation certificate are kept offline. A key that is lost or
suspected compromised is revoked with that certificate on both keyservers, `lockrot-release-key.asc`
is replaced in the repository, and the changelog names the new fingerprint. See the
[PHAR page](https://lockrot.dev/phar/) for the full verification walkthrough.

From 0.6.0 on the archive is also reproducible: `build/build-phar.sh` on the tagged commit, with the
Box release the script pins and the Composer version `.github/workflows/phar.yml` pins at that tag,
gives the published bytes on any PHP version, so the release can be checked against its source and
not only against its signer. The [PHAR page](https://lockrot.dev/phar/#rebuilding-it-yourself) has
the recipe.

## What lockrot does and does not do

lockrot reads `composer.json` and `composer.lock` and never writes to either. The PHAR always runs
the inspected project with `--no-plugins`, so it never executes that project's Composer plugins.
Network access is limited to the configured Composer repositories, the GitHub API (repository
activity checks, and release lookups for `self-update`) and, for `self-update` only, the release
downloads on `github.com` (the archive, its checksum, signature and `lockrot.phar.meta.json`, which
`--check` reads too); `GITHUB_TOKEN` and `LOCKROT_GITHUB_TOKEN` are read from the environment and
are never written anywhere.

lockrot does not check for known CVEs in your dependencies. For that, use `composer audit`.
