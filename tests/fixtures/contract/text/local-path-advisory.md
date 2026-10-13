### lockrot: 1 of 1 packages flagged — critical 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **critical** | `mautic/core-lib` | 4.3.x-dev | pinned · vulnerable | `32 = pinned 16 + vulnerable 16 [high advisory]`<br>**Do:** no move: it is installed from a local path (your own package)<br>*pinned:* pinned to branch snapshot 4.3.x-dev<br>*vulnerable:* 5 advisories: 1 high, 4 medium; worst: CVE-2022-25768 Mautic vulnerable to Improper Access Control in UI upgrade process | direct |

flags: pinned 1 · vulnerable 1 · 1 package carries more than one
depth: every flagged package is named in composer.json · direct 1
security: 1 vulnerable package · 5 advisories: 1 high, 4 medium
libyears: the one package could not be measured
advisories: 1 package asked · packagist.org answered (5 on record for 1 package)

- note: 1 package is not from a Composer repository: its release metadata and repository activity were not read

<sub>1 package checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
