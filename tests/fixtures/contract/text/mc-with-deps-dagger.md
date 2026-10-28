### lockrot: 1 of 2 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `symfony/routing` | v6.4.28 | vulnerable | `16 = vulnerable 16 [high advisory]`<br>**Do:** `composer update 'symfony/routing:^6.4.41' --with-dependencies` (gets v6.4.41) — clears the advisory; Composer's solver checked it against the lock<br>*vulnerable:* 1 advisory: 1 high — all fixed on 6.x; advisory: CVE-2026-48784 UrlGenerator Dot-Segment Encoding Skips Every Other Chained \`../\` or \`./\` → Generated URL Collapses Off-Route Under RFC 3986 Normalization<br>*age:* installed v6.4.28 released 2025-10-31 (0.9 years ago); 6.x last released v6.4.41 2026-07-03 (0.2 years ago), its newest release; last push 2026-09-29 (less than three weeks ago) | direct |
| ok | `acme/rt-kernel` | 1.0.0 | ok | `0` | direct |

flags: vulnerable 1
depth: every flagged package is named in composer.json · direct 1
security: 1 vulnerable package · 1 advisory: 1 high
libyears: 0.7 behind across all 2 packages · 0.7 from direct requirements · furthest behind symfony/routing v6.4.28 at 0.7
advisories: 2 packages asked · packagist.org answered (4 on record for 1 package)
moves: checked with the solver of Composer 2.10.3 against the lock, with advisory blocking turned off by `config.audit.block-insecure`; ext-\*, lib-\*, php-debug, php-ipv6 and php-zts requirements not checked
php: the lock installs on PHP 8.1 to 8.5

<sub>2 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
