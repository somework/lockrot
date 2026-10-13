### lockrot: 1 of 4 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `league/commonmark` | 2.9.1 | vulnerable | `16 = vulnerable 16 [high advisory]`<br>**Do:** composer update league/commonmark (gets 2.10.3) — clears 3 advisories<br>*vulnerable:* 3 advisories: 2 high, 1 medium — all fixed on 2.x; worst: PKSA-m4t9-vsgq-8khn league/commonmark: Quadratic-time denial of service in the GitHub Flavored Markdown Table extension block-start scan<br>*age:* installed 2.9.1 released 2026-08-09 (0.2 years ago); 2.x last released 2.10.3 2026-09-21 (0.1 years ago), its newest release; last push 2026-10-01 (less than three weeks ago) | laravel/framework, laravel/nightwatch, spatie/laravel-markdown |

flags: vulnerable 1
depth: 1 of 1 flagged package is named by no line of composer.json · 1 hop 1 · deepest: `league/commonmark`, 1 hop (`laravel/framework` › `league/commonmark`)
security: 1 vulnerable package · 3 advisories: 2 high, 1 medium
libyears: 0.1 behind across the one package · 0.0 from direct requirements · furthest behind league/commonmark 2.9.1 at 0.1
pulled in by: `laravel/framework` 1 · `laravel/nightwatch` 1 · `spatie/laravel-markdown` 1
advisories: 1 package asked · packagist.org answered (3 on record for 1 package)

**Update now:** `composer update league/commonmark`

<sub>4 packages checked — data as of 2026-10-14 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
