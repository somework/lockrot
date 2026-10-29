### lockrot: 2 of 2 packages flagged — critical 1 · high 1

**gate: fails** · --fail-on=high · 2 findings fail it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **critical** | `mautic/core-lib` | 4.3.x-dev | pinned · capped · vulnerable | `34 = pinned 16 + capped 2 [¼ of 8] + vulnerable 16 [high advisory]`<br>**Do:** no move: it is installed from a local path (your own package)<br>*pinned:* pinned to branch snapshot 4.3.x-dev<br>*capped:* requires php \>=7.4.0 \<8.1: it stops at 8.0, so the newest PHP, 8.5, is excluded<br>*vulnerable:* 5 advisories: 1 high, 4 medium; worst: CVE-2022-25768 Mautic vulnerable to Improper Access Control in UI upgrade process<br>pulls in 1 flagged package: robrichards/xmlseclibs (old-promise · vulnerable) | direct |
| **high** | `robrichards/xmlseclibs` | 3.1.1 | old-promise · vulnerable | `24 = old-promise 16 ÷ 2 transitive + vulnerable 16 [high advisory]`<br>**Do:** `composer update robrichards/xmlseclibs` (gets 3.1.5) — clears old-promise and 2 advisories<br>*old-promise:* released 2020-09-05 for PHP 5 (php "\>= 5.4"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested<br>*vulnerable:* 2 advisories: 1 high, 1 medium — all fixed on 3.x; worst: CVE-2026-32313 xmlseclibs: Missing AES-GCM Authentication Tag Validation on Encrypted Nodes Allows for Unauthorized Decryption<br>*age:* installed 3.1.1 released 2020-09-05 (6.1 years ago); 3.x last released 3.1.5 2026-03-13 (0.6 years ago); newest release 4.0.0 2026-08-22 (0.1 years ago); last push 2026-08-22 (0.1 years ago) | mautic/core-lib |

flags: pinned 1 · old-promise 1 · capped 1 · vulnerable 2 · 2 packages carry more than one
depth: 1 of 2 flagged packages is named by no line of composer.json · direct 1 · 4 hops 1 · deepest: `robrichards/xmlseclibs`, 4 hops (`mautic/core-lib` › `lightsaml/sp-bundle` › `lightsaml/symfony-bridge` › `lightsaml/lightsaml` › `robrichards/xmlseclibs`)
security: 2 vulnerable packages · 7 advisories: 2 high, 5 medium
libyears: 6.0 behind across 1 of 2 packages · 0.0 from direct requirements · furthest behind robrichards/xmlseclibs 3.1.1 at 6.0
pulled in by: `mautic/core-lib` 1
advisories: 2 packages asked · packagist.org answered (9 on record for 2 packages)
php: the lock installs on PHP 7.4 to 8.0, so your target, 8.4, is excluded; the update of the package that excludes it is not checked (the run checked no move)

**Update now:** `composer update robrichards/xmlseclibs`

- note: 1 package is not from a Composer repository: its release metadata and repository activity were not read

<sub>2 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
