### lockrot: 1 of 3 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `pagerfanta/twig` | v3.8.0 | left-behind | `16 = left-behind 16`<br>**Do:** raise require.php to \>=8.1, then require pagerfanta/twig ^4.9 — clears left-behind; 4.x needs php ^8.1, require.php allows 7.4 (a major raise); the target php 8.4 runs it; a new major brings its own requirements, which lockrot does not solve<br>*left-behind:* branch 3.x last released 2023-04-15 (3.5 years ago); 4.x released v4.9.0 (2025-03-08), needs php ^8.1 above the project's php \>=7.4; no releasing branch within reach<br>pulls in 1 flagged package: pagerfanta/core (left-behind)<br>checks missing: S2 (undated releases) | direct |

flags: left-behind 1
depth: every flagged package is named in composer.json · direct 1
libyears: at least 1.9 behind across the one package · 1.9 from direct requirements · furthest behind pagerfanta/twig v3.8.0 at 1.9 or more
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)
php: the lock installs on PHP 7.4 to 8.5

<sub>3 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
