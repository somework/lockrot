### lockrot: 1 of 1 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `symfony/yaml` | v5.4.23 | vulnerable | `16 = vulnerable 16 [unrated advisory 8 × 2: no reachable fix]`<br>**Do:** no single release fixes every advisory; see each advisory<br>*vulnerable:* 3 advisories: 1 medium, 1 unrated, 1 low — 2 of 3 fixed on 5.x; weighs most: CVE-2026-45305 YAML Parser ReDoS via Catastrophic Backtracking in Parser::cleanup() Regex<br>*age:* installed v5.4.23 released 2023-04-23 (3.4 years ago); 5.x last released v5.4.53 2026-05-20 (0.4 years ago); newest release v8.1.8 2026-09-26 (less than three weeks ago); last push 2026-10-01 (less than three weeks ago) | direct |

flags: vulnerable 1
depth: every flagged package is named in composer.json · direct 1
security: 1 vulnerable package · 3 advisories: 1 medium, 1 unrated, 1 low
libyears: 3.4 behind across the one package · 3.4 from direct requirements · furthest behind symfony/yaml v5.4.23 at 3.4
advisories: 1 package asked · packagist.org answered (3 on record for 1 package)

<sub>1 package checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
