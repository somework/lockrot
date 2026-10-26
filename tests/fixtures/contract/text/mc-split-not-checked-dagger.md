### lockrot: 1 of 3 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `symfony/routing` | v6.4.28 | capped · vulnerable | `24 = capped 8 + vulnerable 16 [high advisory]`<br>**Do:** composer update 'symfony/routing:^6.4.41' (gets v6.4.41) — clears capped and the advisory; not checked with Composer's solver (Composer refuses the lock itself), so it holds only if Composer resolves it<br>*capped:* requires php \>=8.1 \<8.5: it stops at 8.4, so the newest PHP, 8.5, is excluded<br>*vulnerable:* 1 advisory: 1 high — all fixed on 6.x; advisory: CVE-2026-48784 UrlGenerator Dot-Segment Encoding Skips Every Other Chained \`../\` or \`./\` → Generated URL Collapses Off-Route Under RFC 3986 Normalization<br>*age:* installed v6.4.28 released 2025-10-31 (0.9 years ago); 6.x last released v6.4.41 2026-07-06 (0.2 years ago), its newest release; last push 2026-09-29 (less than three weeks ago) | direct |

flags: capped 1 · vulnerable 1 · 1 package carries more than one
depth: every flagged package is named in composer.json · direct 1
security: 1 vulnerable package · 1 advisory: 1 high
libyears: 0.7 behind across the one package · 0.7 from direct requirements · furthest behind symfony/routing v6.4.28 at 0.7
advisories: 1 package asked · packagist.org answered (4 on record for 1 package)
moves: not checked, because Composer's solver refuses the lock itself (see the moves\_not\_checked note)
php: the lock installs on PHP 8.1 to 8.4; `symfony/routing` excludes 8.5

- note: moves not checked: Composer's solver refuses the lock itself at php 8.4.99, so it would refuse every move: acme/rt-glue 1.0.0 requires acme/rt-tokens ^2.0.

<sub>3 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
