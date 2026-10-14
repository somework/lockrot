### lockrot: 1 of 1 packages flagged — low 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| low | `symfony/yaml` | v5.4.23 | vulnerable | `2 = vulnerable 2 [low advisory]`<br>**Do:** composer update symfony/yaml (gets v5.4.53) — clears 3 advisories<br>*vulnerable:* 3 advisories: 3 low — all fixed on 5.x; worst: CVE-2026-45133 YAML Parser Stack Exhaustion via Unbounded Recursion in Nested Blocks, Sequences, and Mappings<br>*age:* installed v5.4.23 released 2023-04-23 (3.4 years ago); 5.x last released v5.4.53 2026-05-20 (0.4 years ago); newest release v8.1.8 2026-09-26 (less than three weeks ago); last push 2026-10-01 (less than three weeks ago) | direct |

flags: vulnerable 1
depth: every flagged package is named in composer.json · direct 1
security: 1 vulnerable package · 3 advisories: 3 low
libyears: 3.4 behind across the one package · 3.4 from direct requirements · furthest behind symfony/yaml v5.4.23 at 3.4
advisories: 1 package asked · packagist.org answered (3 on record for 1 package)

**Update now:** `composer update symfony/yaml`

<sub>1 package checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
