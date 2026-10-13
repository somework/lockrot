### lockrot: 1 of 3 packages flagged — medium 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| medium | `league/flysystem` | 1.1.9 | left-behind · vulnerable | `10 = left-behind 16 ÷ 2 transitive + vulnerable 2 [low advisory]`<br>**Do:** move league/flysystem to ^3.36 through mautic/core-lib — clears left-behind and the advisory; 3.x needs php ^8.0.2; the target php 8.4 runs it; a new major brings its own requirements, which lockrot does not solve; what requires it must allow it<br>*left-behind:* branch 1.x last released 2022-10-04 (4.0 years ago); 3.x released 3.36.0 (2026-09-02)<br>*vulnerable:* 1 advisory: 1 low — none fixed on 1.x; advisory: CVE-2026-102601 Flysystem: WhitespacePathNormalizer's control-character (CorruptedPathDetected) check is bypassed by malformed UTF-8 in the path, affecting every adapter | mautic/core-lib |

flags: left-behind 1 · vulnerable 1 · 1 package carries more than one
depth: 1 of 1 flagged package is named by no line of composer.json · 2 hops 1 · deepest: `league/flysystem`, 2 hops (`mautic/core-lib` › `barryvdh/elfinder-flysystem-driver` › `league/flysystem`)
security: 1 vulnerable package · 1 advisory: 1 low
libyears: 4.7 behind across the one package · 0.0 from direct requirements · furthest behind league/flysystem 1.1.9 at 4.7
pulled in by: `mautic/core-lib` 1
advisories: 1 package asked · packagist.org answered (1 on record for 1 package)

**Update now:** `composer update league/flysystem`

- note: 1 package is not from a Composer repository: its release metadata and repository activity were not read

<sub>3 packages checked — data as of 2026-10-14 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
