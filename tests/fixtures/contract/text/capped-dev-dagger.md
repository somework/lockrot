### lockrot: 1 of 4 packages flagged — low 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| low | `laminas/laminas-code` | 4.7.1 | capped | `4 = capped 8 ÷ 2 dev`<br>**Do:** raise require.php to \>=8.2, then require laminas/laminas-code ^4.17.0 — clears capped; 4.x needs php \~8.2.0 \|\| \~8.3.0 \|\| \~8.4.0 \|\| \~8.5.0, require.php allows 7.4 (a major raise); the target php 8.4 runs it<br>*capped:* requires php \>=7.4, \<8.2: it stops at 8.1, so the newest PHP, 8.5, is excluded<br>*age:* installed 4.7.1 released 2022-11-21 (3.9 years ago); 4.x last released 4.17.0 2025-11-01 (0.9 years ago), its newest release; last push 2026-09-30 (less than three weeks ago) | direct |

flags: capped 1
depth: every flagged package is named in composer.json · direct 1 · 1 in packages-dev
libyears: 2.9 behind across the one package · 2.9 from direct requirements · furthest behind laminas/laminas-code 4.7.1 at 2.9
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)
php: without require-dev the lock installs on PHP 7.4 to 8.5, and with require-dev on PHP 7.4 to 8.1, so your target, 8.4, is excluded; the update of the package that excludes it is not checked (the run checked no move)

<sub>4 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
