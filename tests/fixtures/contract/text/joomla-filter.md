### lockrot: 1 of 4 packages flagged — medium 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| medium | `joomla/filter` | 2.0.5 | vulnerable | `8 = vulnerable 8 [medium advisory]`<br>**Do:** raise require.php to \>=8.1, then require joomla/filter ^3.0.5 — clears the advisory; 3.x needs php ^8.1.0, require.php allows 7.2.5 (a major raise); the target php 8.4 runs it; a new major brings its own requirements, which lockrot does not solve<br>*vulnerable:* 1 advisory: 1 medium — all fixed on 2.x (blocked); advisory: CVE-2025-54476 Joomla! CMS vulnerable to XSS via the input filter<br>*age:* installed 2.0.5 released 2024-08-20 (2.1 years ago); 2.x last released 2.0.6 2025-09-30 (1.0 years ago); newest release 3.0.7 2026-09-29 (less than three weeks ago); last push 2026-09-29 (less than three weeks ago) | direct |

flags: vulnerable 1
depth: every flagged package is named in composer.json · direct 1
security: 1 vulnerable package · 1 advisory: 1 medium
libyears: 2.1 behind across the one package · 2.1 from direct requirements · furthest behind joomla/filter 2.0.5 at 2.1
advisories: 1 package asked · packagist.org answered (1 on record for 1 package)

<sub>4 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
