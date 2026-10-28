### lockrot: 1 of 2 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `typo3/html-sanitizer` | v2.2.0 | vulnerable | `16 = vulnerable 16 [high advisory]`<br>**Do:** composer update 'typo3/html-sanitizer:^2.3.2' (gets v2.3.2) — clears the advisory; Composer's solver checked it against the lock<br>*vulnerable:* 1 advisory: 1 high — all fixed on 2.x; advisory: CVE-2026-47345 TYPO3-CORE-SA-2026-006: TYPO3 HTML Sanitizer allows Cross-Site Scripting<br>*age:* installed v2.2.0 released 2024-07-12 (2.2 years ago); 2.x last released v2.3.5 2026-07-03 (0.2 years ago), its newest release; last push 2026-07-03 (0.2 years ago) | direct |
| ok | `acme/san-cap` | 1.0.0 | ok | `0` | direct |

flags: vulnerable 1
depth: every flagged package is named in composer.json · direct 1
security: 1 vulnerable package · 1 advisory: 1 high
libyears: 2.0 behind across all 2 packages · 2.0 from direct requirements · furthest behind typo3/html-sanitizer v2.2.0 at 2.0
advisories: 2 packages asked · packagist.org answered (6 on record for 1 package)
moves: checked with the solver of Composer 2.10.3 against the lock; ext-\*, lib-\*, php-debug, php-ipv6 and php-zts requirements not checked
php: the lock installs on PHP 8.1 to 8.5

**Update now:** `composer update 'typo3/html-sanitizer:^2.3.2'`

<sub>2 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
