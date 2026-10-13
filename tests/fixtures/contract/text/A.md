### lockrot: 1 of 2 packages flagged — critical 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **critical** | `twig/twig` | v2.16.1 | vulnerable | `32 = vulnerable 32 [critical advisory]`<br>**Do:** raise require.php to \>=8.1, then require twig/twig ^3.27.0 — clears 15 advisories; 3.x needs php \>=8.1.0, require.php allows 7.2 (a major raise); the target php 8.4 runs it; held by composer.json (requires ^2.0) and symfony/twig-bridge v3.4.47 (requires ^1.41\|^2.10); the packages that hold it may need to move too; a new major brings its own requirements, which lockrot does not solve<br>*vulnerable:* 15 advisories: 1 critical, 3 high, 4 medium, 2 unrated, 5 low — none fixed on 2.x; worst: CVE-2026-46633 PHP code injection via \`{% use %}\` template name<br>*age:* installed v2.16.1 released 2024-09-09 (2.1 years ago), the newest on 2.x; newest release v3.30.0 2026-09-25 (0.1 years ago); last push 2026-09-30 (less than three weeks ago) | direct |

flags: vulnerable 1
depth: every flagged package is named in composer.json · direct 1
security: 1 vulnerable package · 15 advisories: 1 critical, 3 high, 4 medium, 2 unrated, 5 low
libyears: 2.0 behind across the one package · 2.0 from direct requirements · furthest behind twig/twig v2.16.1 at 2.0
advisories: 1 package asked · packagist.org answered (15 on record for 1 package)

<sub>2 packages checked — data as of 2026-10-14 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
