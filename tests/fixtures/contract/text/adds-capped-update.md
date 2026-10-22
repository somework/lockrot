### lockrot: 1 of 2 packages flagged — critical 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **critical** | `phpoffice/phpspreadsheet` | 1.23.0 | vulnerable | `32 = vulnerable 32 [critical advisory]`<br>**Do:** composer update phpoffice/phpspreadsheet (gets 1.30.7) — clears 28 advisories; it stops below the newest PHP, so capped would count<br>*vulnerable:* 28 advisories: 2 critical, 15 high, 11 medium — all fixed on 1.x; worst: CVE-2026-34084 PhpSpreadsheet has SSRF/RCE in IOFactory::load when $filename is user controlled<br>*age:* installed 1.23.0 released 2022-04-24 (4.4 years ago); 1.x last released 1.30.7 2026-09-17 (less than three weeks ago); newest release 3.10.8 2026-09-17 (less than three weeks ago); last push 2026-10-01 (less than three weeks ago) | mautic/core-lib |

flags: vulnerable 1
depth: 1 of 1 flagged package is named by no line of composer.json · 1 hop 1 · deepest: `phpoffice/phpspreadsheet`, 1 hop (`mautic/core-lib` › `phpoffice/phpspreadsheet`)
security: 1 vulnerable package · 28 advisories: 2 critical, 15 high, 11 medium
libyears: 4.4 behind across the one package · 0.0 from direct requirements · furthest behind phpoffice/phpspreadsheet 1.23.0 at 4.4
pulled in by: `mautic/core-lib` 1
advisories: 1 package asked · packagist.org answered (31 on record for 1 package)
php: the lock installs on PHP 7.3 to 8.5

**Update now:** `composer update phpoffice/phpspreadsheet`

- note: 1 package is not from a Composer repository: its release metadata and repository activity were not read

<sub>2 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
