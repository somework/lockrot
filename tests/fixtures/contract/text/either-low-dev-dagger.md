### lockrot: 1 of 2 packages flagged — low 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| low | `balping/json-raw-encoder` | v1.0.2 | stale · vulnerable | `4 = (stale 8 ÷ 2 transitive + vulnerable 4 [low advisory 2 × 2: no reachable fix]) ÷ 2 dev`<br>**Do:** no release fixes it<br>*stale:* last release 2023-03-27 (3.5 years ago); last commit 2023-03-27 (3.5 years ago)<br>*vulnerable:* 1 advisory: 1 low — none fixed on 1.x; advisory: CVE-2026-00004 an advisory added to show the either opening (‡) | akaunting/laravel-apexcharts |

flags: stale 1 · vulnerable 1 · 1 package carries more than one
depth: 1 of 1 flagged package is named by no line of composer.json · 1 hop 1 · 1 in packages-dev · deepest: `balping/json-raw-encoder`, 1 hop (`akaunting/laravel-apexcharts` › `balping/json-raw-encoder`)
security: 1 vulnerable package · 1 advisory: 1 low
libyears: 0.0 behind across the one package
pulled in by: `akaunting/laravel-apexcharts` 1
advisories: 1 package asked · packagist.org answered (1 on record for 1 package)
php: the lock installs on PHP 8.1 to 8.5

<sub>2 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
