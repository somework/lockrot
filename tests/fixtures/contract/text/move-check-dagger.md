### lockrot: 1 of 1 packages flagged — medium 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| medium | `typo3/html-sanitizer` | v2.2.0 | vulnerable | `8 = vulnerable 8 [medium advisory]`<br>**Do:** composer update 'typo3/html-sanitizer:^2.3.2' --with-dependencies (gets v2.3.2) — clears 2 advisories; Composer's solver checked it against the lock<br>*vulnerable:* 2 advisories: 1 medium, 1 low — all fixed on 2.x; worst: CVE-2026-47345 TYPO3-CORE-SA-2026-006: TYPO3 HTML Sanitizer allows Cross-Site Scripting<br>*age:* installed v2.2.0 released 2024-07-12 (2.2 years ago); 2.x last released v2.3.2 2026-06-08 (0.3 years ago), its newest release; last push 2026-06-08 (0.3 years ago) | direct |

flags: vulnerable 1
depth: every flagged package is named in composer.json · direct 1
security: 1 vulnerable package · 2 advisories: 1 medium, 1 low
libyears: 1.9 behind across the one package · 1.9 from direct requirements · furthest behind typo3/html-sanitizer v2.2.0 at 1.9
advisories: 1 package asked · packagist.org answered (6 on record for 1 package)

**Update now:** `composer update typo3/html-sanitizer`

<sub>1 package checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
