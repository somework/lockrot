### lockrot: 1 of 76 packages flagged — low 1

**gate: passes** · --fail-on=high · no finding fails it · advisories not applied to 76 packages: not checked (Composer below 2.4) · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| low | `league/config` | v1.2.0 | stale | `4 = stale 8 ÷ 2 transitive`<br>**Do:** —<br>*stale:* last release 2022-12-11 (3.8 years ago) | laravel/framework, laravel/tinker |

flags: stale 1
depth: 1 of 1 flagged package is named by no line of composer.json · 2 hops 1 · deepest: `league/config`, 2 hops (`laravel/framework` › `league/commonmark` › `league/config`)
security: no vulnerable package · advisories not checked
libyears: 0.5 behind across 73 of 76 packages · 0.0 from direct requirements · furthest behind brick/math 0.18.0 at 0.2
pulled in by: `laravel/framework` 1 · `laravel/tinker` 1
advisories: 0 packages asked, 76 not asked
php: the lock installs on PHP 8.4 to 8.5

<sub>76 packages checked — data as of 2026-09-14 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
