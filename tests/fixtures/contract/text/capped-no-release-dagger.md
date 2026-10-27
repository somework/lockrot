### lockrot: 1 of 4 packages flagged — medium 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| medium | `laminas/laminas-code` | 4.7.1 | capped | `8 = capped 8`<br>**Do:** no move: no release above 4.7.1 admits PHP 8.5<br>*capped:* requires php \>=7.4, \<8.2: it stops at 8.1, so the newest PHP, 8.5, is excluded<br>*age:* installed 4.7.1 released 2022-11-21 (3.9 years ago); 4.x last released 4.16.0 2024-11-20 (1.9 years ago), its newest release; last push 2026-09-30 (less than three weeks ago) | direct |

flags: capped 1
depth: every flagged package is named in composer.json · direct 1
libyears: 2.0 behind across the one package · 2.0 from direct requirements · furthest behind laminas/laminas-code 4.7.1 at 2.0
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)
php: the lock installs on PHP 7.4 to 8.1, so your target, 8.4, is excluded; the update of the package that excludes it is not checked (the run checked no move)

<sub>4 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
