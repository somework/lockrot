### lockrot: 1 of 15 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `laravel/framework` | v9.52.16 | vulnerable | `16 = vulnerable 16 [high advisory]`<br>**Do:** raise require.php to \>=8.2, then require laravel/framework ^12.69.0 — clears 6 advisories; 12.x needs php ^8.2, require.php allows 8.1 (a minor raise); the target php 8.4 runs it; held by composer.json (requires ^9.0) and pragmarx/google2fa-laravel v2.2.0 (requires ^5.4.36\|^6.0\|^7.0\|^8.0\|^9.0\|^10.0\|^11.0); the packages that hold it may need to move too; a new major brings its own requirements, which lockrot does not solve<br>*vulnerable:* 6 advisories: 2 high, 2 medium, 1 unrated, 1 low — 1 of 6 fixed on 9.x; worst: PKSA-3r5d-mb8f-1qw9 Laravel Framework: CRLF injection in default email rule<br>*age:* installed v9.52.16 released 2023-10-03 (3.0 years ago); 9.x last released v9.52.22 2026-08-12 (0.1 years ago); newest release v11.57.0 2026-09-29 (less than three weeks ago); last push 2026-09-30 (less than three weeks ago)<br>pulls in 11 flagged packages: league/commonmark (vulnerable), symfony/http-foundation (vulnerable), symfony/mime (vulnerable), symfony/process (vulnerable), nesbot/carbon (vulnerable) and 6 more | direct |

flags: vulnerable 1
depth: every flagged package is named in composer.json · direct 1
security: 1 vulnerable package · 6 advisories: 2 high, 2 medium, 1 unrated, 1 low
libyears: 3.0 behind across the one package · 3.0 from direct requirements · furthest behind laravel/framework v9.52.16 at 3.0
advisories: 1 package asked · packagist.org answered (74 on record for 10 packages)
php: the lock installs on PHP 8.1 to 8.5

<sub>15 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
