### lockrot: 1 of 4 packages flagged — critical 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **critical** | `twig/twig` | v1.43.1 | old-promise · vulnerable | `48 = old-promise 16 + vulnerable 32 [critical advisory]`<br>**Do:** raise require.php to \>=8.1, then require twig/twig ^3.27.0 — clears 16 advisories; 3.x needs php \>=8.1.0, require.php allows 7.1.3 (a major raise); the target php 8.4 runs it; not checked: old-promise; a new major brings its own requirements, which lockrot does not solve<br>*old-promise:* released 2020-08-05 for PHP 7 (php "\>=7.1.3"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested<br>*vulnerable:* 16 advisories: 1 critical, 3 high, 5 medium, 2 unrated, 5 low — 2 of 16 fixed on 1.x (php floor raise); worst: CVE-2026-46633 PHP code injection via \`{% use %}\` template name<br>*age:* installed v1.43.1 released 2020-08-05 (6.2 years ago); 1.x last released v1.44.8 2024-09-09 (2.1 years ago); newest release v3.30.0 2026-09-25 (0.1 years ago); last push 2026-09-30 (less than three weeks ago) | direct |

flags: old-promise 1 · vulnerable 1 · 1 package carries more than one
depth: every flagged package is named in composer.json · direct 1
security: 1 vulnerable package · 16 advisories: 1 critical, 3 high, 5 medium, 2 unrated, 5 low
libyears: 6.1 behind across the one package · 6.1 from direct requirements · furthest behind twig/twig v1.43.1 at 6.1
advisories: 1 package asked · packagist.org answered (16 on record for 1 package)

<sub>4 packages checked — data as of 2026-10-14 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
