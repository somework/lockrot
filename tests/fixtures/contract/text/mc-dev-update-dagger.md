### lockrot: 1 of 2 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `guzzlehttp/psr7` | 2.8.0 | vulnerable | `16 = vulnerable 16 [high advisory]`<br>**Do:** composer update 'guzzlehttp/psr7:^2.12.3' (gets 2.12.3) — clears the advisory; Composer's solver checked it against the lock<br>*vulnerable:* 1 advisory: 1 high — all fixed on 2.x; advisory: CVE-2026-59882 guzzlehttp/psr7: Host Confusion via Weak URI Host Validation<br>*age:* installed 2.8.0 released 2025-08-23 (1.1 years ago); 2.x last released 2.12.3 2026-07-02 (0.3 years ago), its newest release; last push 2026-08-24 (0.1 years ago) | guzzlehttp/guzzle |
| ok | `guzzlehttp/guzzle` | 7.10.0 | ok | `0`<br>pulls in 1 flagged package: guzzlehttp/psr7 (vulnerable) | direct |

flags: vulnerable 1
depth: every flagged package is named in composer.json · 1 hop 1 · deepest: `guzzlehttp/psr7`, 1 hop (`guzzlehttp/guzzle` › `guzzlehttp/psr7`) · 1 more is named only in require-dev, which this run did not walk (pass --dev)
security: 1 vulnerable package · 1 advisory: 1 high
libyears: 0.9 behind across all 2 packages · 0.0 from direct requirements · furthest behind guzzlehttp/psr7 2.8.0 at 0.9
pulled in by: `guzzlehttp/guzzle` 1
advisories: 2 packages asked · packagist.org answered (21 on record for 2 packages)
moves: checked with the solver of Composer 2.10.3 against the lock; ext-\*, lib-\*, php-debug, php-ipv6 and php-zts requirements not checked
php: the lock installs on PHP 8.1 to 8.5

**Update now:** `composer update 'guzzlehttp/psr7:^2.12.3'`

<sub>2 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
