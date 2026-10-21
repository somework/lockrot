### lockrot: 1 of 7 packages flagged — critical 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **critical** | `web-token/jwt-key-mgmt` | 3.4.3 | abandoned | `32 = abandoned 32`<br>**Do:** replace with web-token/jwt-library (named by its registry; itself high: vulnerable)<br>*abandoned:* marked abandoned by its registry, replacement: web-token/jwt-library<br>*age:* installed 3.4.3, release date not known (no date); last push 2025-05-07 (1.4 years ago)<br>pulls in 3 flagged packages: web-token/jwt-library (vulnerable), paragonie/sodium\_compat (vulnerable), brick/math (left-behind) | direct |

flags: abandoned 1
depth: every flagged package is named in composer.json · direct 1
libyears: the one package could not be measured
advisories: 1 package asked · packagist.org answered (7 on record for 2 packages)
php: the lock installs on PHP 8.1 to 8.5

<sub>7 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
