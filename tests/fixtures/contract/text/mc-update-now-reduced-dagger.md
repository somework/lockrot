### lockrot: 3 of 3 packages flagged — high 3

**gate: fails** · --fail-on=high · 3 findings fail it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `symfony/routing` | v6.4.28 | vulnerable | `16 = vulnerable 16 [high advisory]`<br>**Do:** `composer update 'symfony/routing:^6.4.41'` (gets v6.4.41) — clears the advisory; Composer's solver checked it against the lock<br>*vulnerable:* 1 advisory: 1 high — all fixed on 6.x; advisory: CVE-2026-48784 UrlGenerator Dot-Segment Encoding Skips Every Other Chained \`../\` or \`./\` → Generated URL Collapses Off-Route Under RFC 3986 Normalization<br>*age:* installed v6.4.28 released 2025-10-31 (0.9 years ago); 6.x last released v6.4.41 2026-07-03 (0.2 years ago), its newest release; last push 2026-09-29 (less than three weeks ago) | direct |
| **high** | `symfony/yaml` | v6.4.26 | vulnerable | `16 = vulnerable 16 [high advisory]`<br>**Do:** `composer update 'symfony/yaml:^6.4.40'` (gets v6.4.40) — clears the advisory; Composer's solver checked it against the lock<br>*vulnerable:* 1 advisory: 1 high — all fixed on 6.x; advisory: CVE-2026-45304 YAML Parser Exponential Memory Allocation via Recursive Collection-Alias Expansion ("Billion Laughs")<br>*age:* installed v6.4.26 released 2025-09-26 (1.0 years ago); 6.x last released v6.4.40 2026-07-04 (0.2 years ago), its newest release; last push 2026-10-01 (less than three weeks ago) | direct |
| **high** | `typo3/html-sanitizer` | v2.2.0 | vulnerable | `16 = vulnerable 16 [high advisory]`<br>**Do:** `composer update 'typo3/html-sanitizer:^2.3.2'` (gets v2.3.2) — clears the advisory; Composer's solver checked it against the lock<br>*vulnerable:* 1 advisory: 1 high — all fixed on 2.x; advisory: CVE-2026-47345 TYPO3-CORE-SA-2026-006: TYPO3 HTML Sanitizer allows Cross-Site Scripting<br>*age:* installed v2.2.0 released 2024-07-12 (2.2 years ago); 2.x last released v2.3.2 2026-07-05 (0.2 years ago), its newest release; last push 2026-07-05 (0.2 years ago) | direct |

flags: vulnerable 3
depth: every flagged package is named in composer.json · direct 3
security: 3 vulnerable packages · 3 advisories: 3 high
libyears: 3.4 behind across all 3 packages · 3.4 from direct requirements · furthest behind typo3/html-sanitizer v2.2.0 at 2.0
advisories: 3 packages asked · packagist.org answered (15 on record for 3 packages)
moves: checked with the solver of Composer 2.10.3 against the lock; ext-\* requirements not checked
php: the lock installs on PHP 8.1 to 8.5

**Update now:** `composer update 'symfony/routing:^6.4.41'`

- note: update now: the command leaves out symfony/yaml, typo3/html-sanitizer, because Composer's solver refuses them together with the others: typo3/html-sanitizer v2.3.2 requires acme/un-x ^2.0.

<sub>3 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
