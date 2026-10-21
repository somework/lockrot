### lockrot: 2 of 4 packages flagged — high 1 · low 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `league/commonmark` | 2.9.1 | vulnerable | `16 = vulnerable 16 [high advisory]`<br>**Do:** composer update league/commonmark (gets 2.10.3) — clears 3 advisories<br>*vulnerable:* 3 advisories: 2 high, 1 medium — all fixed on 2.x; worst: PKSA-m4t9-vsgq-8khn league/commonmark: Quadratic-time denial of service in the GitHub Flavored Markdown Table extension block-start scan<br>*age:* installed 2.9.1 released 2026-08-09 (0.1 years ago); 2.x last released 2.10.3 2026-09-21 (less than three weeks ago), its newest release; last push 2026-10-01 (less than three weeks ago) | laravel/framework, laravel/nightwatch, spatie/laravel-markdown |
| low | `laravel/framework` | v12.65.0 | vulnerable | `2 = vulnerable 2 [low advisory]`<br>**Do:** composer update laravel/framework (gets v12.69.3) — clears the advisory<br>*vulnerable:* 1 advisory: 1 low — all fixed on 12.x; advisory: CVE-2026-102279 Laravel: XSS in Debug Page Information<br>*age:* installed v12.65.0 released 2026-08-05 (0.2 years ago); 12.x last released v12.69.3 2026-09-29 (less than three weeks ago); newest release v11.57.0 2026-09-29 (less than three weeks ago); last push 2026-09-30 (less than three weeks ago)<br>pulls in 1 flagged package: league/commonmark (vulnerable) | direct |
| ok | `laravel/nightwatch` | v1.28.6 | ok | `0`<br>pulls in 1 flagged package: league/commonmark (vulnerable) | direct |
| ok | `spatie/laravel-markdown` | 2.8.0 | ok | `0`<br>pulls in 1 flagged package: league/commonmark (vulnerable) | direct |

flags: vulnerable 2
depth: 1 of 2 flagged packages is named by no line of composer.json · direct 1 · 1 hop 1 · deepest: `league/commonmark`, 1 hop (`laravel/framework` › `league/commonmark`)
security: 2 vulnerable packages · 4 advisories: 2 high, 1 medium, 1 low
libyears: 0.4 behind across all 4 packages · 0.3 from direct requirements · furthest behind laravel/framework v12.65.0 at 0.2
pulled in by: `laravel/framework` 1 · `laravel/nightwatch` 1 · `spatie/laravel-markdown` 1
advisories: 4 packages asked · packagist.org answered (46 on record for 2 packages)
php: the lock installs on PHP 8.4 to 8.5

**Update now:** `composer update league/commonmark laravel/framework`

<sub>4 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
