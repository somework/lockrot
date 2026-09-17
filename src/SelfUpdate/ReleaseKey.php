<?php

declare(strict_types=1);

namespace Lockrot\SelfUpdate;

/**
 * The public half of the lockrot self-update signing key, built into every archive so
 * {@see ReleaseSignatureVerifier} needs nothing from the machine it runs on. The same key is
 * `lockrot-selfupdate-key.pub` in the repository root; a test keeps the two identical.
 *
 * This is not the GPG release key: that one signs `lockrot.phar.asc` for people and PHIVE, this
 * one signs `lockrot.phar.sig` for the archive's own self-update, the way Composer keeps its
 * self-update keys apart from its maintainers' GPG keys. RSA 4096, held by the release workflow
 * as the SELFUPDATE_PRIVATE_KEY secret; SECURITY.md says how it is rotated.
 */
final class ReleaseKey
{
    public const PEM = <<<'PEM'
        -----BEGIN PUBLIC KEY-----
        MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEAzwHpzM9s67sWHTrJ6IoE
        n9BKHrsUUX6pcb8FblZoUqMWq/NCHA7ZgwHhSjKNfYelg5YS7hwvkQLkQBvXqbeO
        AXvdyTewaeM/eOjtRxysNUZcWknTTBeEc0zoVEIJHRbcoSBuqJ0KU4BnVCJzs6Nx
        EplmDrndQxbb+gfbsDi9XxHToD1+Y5K6A3CATzWphhnIxFoiqeAicjhmgsvojyG+
        4ulXMNORg+eA6Q/31JthhmCa/3+fYMs9UXb8X4BDR6n3XdxI7GkHQm0WNu8He3WB
        volB0Po91qQoJsYSIOCyErvKep9GO3DsQ1MEDmhM3QRX0fqF2uVPFy8MnCiZzr7m
        z2VVn5yb2KsRY3GiGp13NvSfiT2q2p1K99CUYhsrEsqVrEsfvPt2R4rwXifReW7O
        NGAJS9sgp4qhKhyPvVCzHZQkVAIHs+BsOf6DLhaZc5cy3F+puEuNgJN86P1LiEVz
        VSRtUAMkiE5DYasLe4bJlju3URoA41y2O5krl+6VUQoLBwipMdPkK/4+6ykLbpv+
        3h3kMl/W/fzZncB3jsynxcbOubESGACPF7+Ai1YztXXM+cqb/lUSeKArTDrEysrv
        f8pJ3hgS2mu+SqUiseXDsdy31w9HB3580rWDhuKlViY0XZI7BX9lAwjAGHMM2Ah0
        A49b9NR3ujEh9kfy1AauIlMCAwEAAQ==
        -----END PUBLIC KEY-----
        PEM;
}
