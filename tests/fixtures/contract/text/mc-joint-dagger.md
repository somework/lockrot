### lockrot: 1 of 1 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `guzzlehttp/guzzle` | 7.10.0 | vulnerable | `16 = vulnerable 16 [high advisory]`<br>**Do:** require guzzlehttp/guzzle ^8.0.1 — clears the advisory; 8.x needs php ^8.1, which require.php allows; the target php 8.4 runs it; a new major brings its own requirements; Composer's solver checked it against the lock, with an update of acme/gz-client (to 1.1.0)<br>*vulnerable:* 1 advisory: 1 high — none fixed on 7.x; advisory: CVE-2026-69246 Guzzle: Noncanonical host can bypass host-based checks<br>*age:* installed 7.10.0 released 2025-08-23 (1.1 years ago); 7.x last released 7.15.2 2026-07-03 (0.2 years ago); newest release 8.0.1 2026-07-04 (0.2 years ago); last push 2026-09-06 (0.1 years ago) | direct |

flags: vulnerable 1
depth: every flagged package is named in composer.json · direct 1
security: 1 vulnerable package · 1 advisory: 1 high
libyears: 0.9 behind across the one package · 0.9 from direct requirements · furthest behind guzzlehttp/guzzle 7.10.0 at 0.9
advisories: 1 package asked · packagist.org answered (15 on record for 1 package)
moves: checked with the solver of Composer 2.10.3 against the lock; ext-\* requirements not checked
php: the lock installs on PHP 8.1 to 8.5

<sub>1 package checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
