### lockrot: 1 of 2 packages flagged — medium 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| medium | `moneyphp/money` | v4.7.1 | capped | `8 = capped 8`<br>**Do:** composer update moneyphp/money (gets v4.9.0) — clears capped<br>*capped:* requires php \~8.1.0 \|\| \~8.2.0 \|\| \~8.3.0 \|\| \~8.4.0: it stops at 8.4, so the newest PHP, 8.5, is excluded<br>*age:* installed v4.7.1 released 2025-06-06 (1.3 years ago); 4.x last released v4.9.0 2026-05-04 (0.4 years ago), its newest release; last push 2026-07-07 (0.2 years ago) | direct |

flags: capped 1
depth: every flagged package is named in composer.json · direct 1
libyears: 0.9 behind across the one package · 0.9 from direct requirements · furthest behind moneyphp/money v4.7.1 at 0.9
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)
php: the lock installs on PHP 8.1 to 8.4; `moneyphp/money` excludes 8.5

<sub>2 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
