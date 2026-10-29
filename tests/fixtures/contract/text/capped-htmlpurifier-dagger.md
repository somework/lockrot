### lockrot: 1 of 2 packages flagged — low 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| low | `ezyang/htmlpurifier` | v4.17.0 | capped | `4 = capped 8 ÷ 2 transitive`<br>**Do:** `composer update ezyang/htmlpurifier` (gets v4.19.1) — clears capped<br>*capped:* requires php \~5.6.0 \|\| \~7.0.0 \|\| \~7.1.0 \|\| \~7.2.0 \|\| \~7.3.0 \|\| \~7.4.0 \|\| \~8.0.0 \|\| \~8.1.0 \|\| \~8.2.0 \|\| \~8.3.0: it stops at 8.3, so the newest PHP, 8.5, is excluded<br>*age:* installed v4.17.0 released 2023-11-17 (2.9 years ago); 4.x last released v4.19.1 2026-09-15 (less than three weeks ago), its newest release; last push 2026-09-15 (less than three weeks ago) | mautic/core-lib |

flags: capped 1
depth: 1 of 1 flagged package is named by no line of composer.json · 1 hop 1 · deepest: `ezyang/htmlpurifier`, 1 hop (`mautic/core-lib` › `ezyang/htmlpurifier`)
libyears: 2.8 behind across the one package · 0.0 from direct requirements · furthest behind ezyang/htmlpurifier v4.17.0 at 2.8
pulled in by: `mautic/core-lib` 1
advisories: 1 package asked · packagist.org answered (4 on record for 1 package)
php: the lock installs on PHP 5.6 to 8.3, so your target, 8.4, is excluded; the update of the package that excludes it is not checked (the run checked no move)

- note: 1 package is not from a Composer repository: its release metadata and repository activity were not read

<sub>2 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
