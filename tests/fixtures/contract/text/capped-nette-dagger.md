### lockrot: 1 of 5 packages flagged — low 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| low | `nette/schema` | v1.3.2 | capped | `4 = capped 8 ÷ 2 transitive`<br>**Do:** `composer update nette/schema` (gets v1.3.6) — clears capped<br>*capped:* requires php 8.1 - 8.4: it stops at 8.4, so the newest PHP, 8.5, is excluded<br>*age:* installed v1.3.2 released 2024-10-06 (2.0 years ago); 1.x last released v1.3.6 2026-08-16 (0.1 years ago), its newest release; last push 2026-09-10 (0.1 years ago) | laravel/framework and pragmarx/google2fa-laravel |

flags: capped 1
depth: 1 of 1 flagged package is named by no line of composer.json · 2 hops 1 · deepest: `nette/schema`, 2 hops (`pragmarx/google2fa-laravel` › `league/config` › `nette/schema`)
libyears: 1.9 behind across the one package · 0.0 from direct requirements · furthest behind nette/schema v1.3.2 at 1.9
pulled in by: `laravel/framework` 1 · `pragmarx/google2fa-laravel` 1
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)
php: the lock installs on PHP 8.1 to 8.4; `nette/schema` excludes 8.5

<sub>5 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
