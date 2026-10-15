### lockrot: 1 of 1 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `symfony/yaml` | v3.4.47 | left-behind · old-promise · vulnerable | `22 = left-behind 16 + old-promise 4 [¼ of 16] + vulnerable 2 [low advisory]`<br>**Do:** raise require.php to \>=7.2.5, then require symfony/yaml ^5.4.52 — clears left-behind and 3 advisories; 5.x needs php \>=7.2.5, require.php allows 7.2 (a patch raise); the target php 8.4 runs it; not checked: old-promise; a new major brings its own requirements, which lockrot does not solve<br>*left-behind:* branch 3.x last released 2020-10-24 (5.9 years ago); 8.x released v8.1.8 (2026-09-26), needs php \>=8.4.1 above the project's php ^7.2 \|\| ^8.0.0; no releasing branch within reach<br>*old-promise:* released 2020-10-24 for PHP 5 (php "^5.5.9\|\>=7.0.8"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested<br>*vulnerable:* 3 advisories: 3 low — none fixed on 3.x; worst: CVE-2026-45133 YAML Parser Stack Exhaustion via Unbounded Recursion in Nested Blocks, Sequences, and Mappings | direct |

flags: left-behind 1 · old-promise 1 · vulnerable 1 · 1 package carries more than one
depth: every flagged package is named in composer.json · direct 1
security: 1 vulnerable package · 3 advisories: 3 low
libyears: 5.9 behind across the one package · 5.9 from direct requirements · furthest behind symfony/yaml v3.4.47 at 5.9
advisories: 1 package asked · packagist.org answered (5 on record for 1 package)

<sub>1 package checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
