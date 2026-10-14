### lockrot: 1 of 1 packages flagged — critical 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **critical** | `web-token/jwt-key-mgmt` | 3.4.3 | abandoned | `32 = abandoned 32`<br>**Do:** replace with web-token/jwt-library (named by its registry)<br>*abandoned:* marked abandoned by its registry, replacement: web-token/jwt-library<br>*age:* installed 3.4.3, release date not known (no date); last push 2025-05-07 (1.4 years ago) | direct |

flags: abandoned 1
depth: every flagged package is named in composer.json · direct 1
libyears: the one package could not be measured
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)

<sub>1 package checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
