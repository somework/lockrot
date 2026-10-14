### lockrot: 1 of 2 packages flagged — medium 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| medium | `genealabs/laravel-pivot-events` | 10.0.1 | left-behind | `8 = left-behind 16 ÷ 2 transitive`<br>**Do:** move genealabs/laravel-pivot-events to ^12.0 through genealabs/laravel-model-caching — clears left-behind; a new major brings its own requirements, which lockrot does not solve; what requires it must allow it; the newest release of genealabs/laravel-model-caching (13.1.11) no longer requires it<br>*left-behind:* branch 10.x last released 2023-03-22 (3.5 years ago); 13.x released 13.1.0 (2026-02-28), needs php ^8.2 above the project's php ^8.1; 12.x released 12.0.0 (2025-02-26) | genealabs/laravel-model-caching |

flags: left-behind 1
depth: 1 of 1 flagged package is named by no line of composer.json · 1 hop 1 · deepest: `genealabs/laravel-pivot-events`, 1 hop (`genealabs/laravel-model-caching` › `genealabs/laravel-pivot-events`)
libyears: 2.9 behind across the one package · 0.0 from direct requirements · furthest behind genealabs/laravel-pivot-events 10.0.1 at 2.9
pulled in by: `genealabs/laravel-model-caching` 1
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)

<sub>2 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
