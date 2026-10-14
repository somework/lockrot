### lockrot: 1 of 5 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `robrichards/xmlseclibs` | 3.1.1 | old-promise · vulnerable | `24 = old-promise 16 ÷ 2 transitive + vulnerable 16 [high advisory]`<br>**Do:** composer update robrichards/xmlseclibs (gets 3.1.5) — clears old-promise and 2 advisories<br>*old-promise:* released 2020-09-05 for PHP 5 (php "\>= 5.4"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested<br>*vulnerable:* 2 advisories: 1 high, 1 medium — all fixed on 3.x; worst: CVE-2026-32313 xmlseclibs: Missing AES-GCM Authentication Tag Validation on Encrypted Nodes Allows for Unauthorized Decryption<br>*age:* installed 3.1.1 released 2020-09-05 (6.1 years ago); 3.x last released 3.1.5 2026-03-13 (0.6 years ago); newest release 4.0.0 2026-08-22 (0.1 years ago); last push 2026-08-22 (0.1 years ago) | mautic/core-lib |

flags: old-promise 1 · vulnerable 1 · 1 package carries more than one
depth: 1 of 1 flagged package is named by no line of composer.json · 4 hops 1 · deepest: `robrichards/xmlseclibs`, 4 hops (`mautic/core-lib` › `lightsaml/sp-bundle` › `lightsaml/symfony-bridge` › `lightsaml/lightsaml` › `robrichards/xmlseclibs`)
security: 1 vulnerable package · 2 advisories: 1 high, 1 medium
libyears: 6.0 behind across the one package · 0.0 from direct requirements · furthest behind robrichards/xmlseclibs 3.1.1 at 6.0
pulled in by: `mautic/core-lib` 1
advisories: 1 package asked · packagist.org answered (2 on record for 1 package)

**Update now:** `composer update robrichards/xmlseclibs`

- note: 1 package is not from a Composer repository: its release metadata and repository activity were not read

<sub>5 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
