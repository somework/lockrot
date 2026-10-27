### lockrot: 1 of 4 packages flagged — medium 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| medium | `laminas/laminas-code` | 4.7.1 | capped | `8 = capped 8`<br>**Do:** composer update laminas/laminas-code (gets 4.18.0); not checked: capped<br>*capped:* requires php \>=7.4, \<8.2: it stops at 8.1, so the newest PHP, 8.5, is excluded<br>*age:* installed 4.7.1 released 2022-11-21 (3.9 years ago); 4.x last released 4.18.0 2026-05-01 (0.4 years ago); newest release 5.0.0 2026-06-01 (0.3 years ago); last push 2026-09-30 (less than three weeks ago) | direct |

flags: capped 1
depth: every flagged package is named in composer.json · direct 1
libyears: 3.5 behind across the one package · 3.5 from direct requirements · furthest behind laminas/laminas-code 4.7.1 at 3.5
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)
php: the lock installs on no PHP, so your target, 8.4, is excluded; the update of the package that excludes it is not checked (the run checked no move)

<sub>4 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
