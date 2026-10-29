### lockrot: 1 of 2 packages flagged — low 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| low | `symfony/polyfill-intl-idn` | v1.37.0 | vulnerable | `2 = vulnerable 2 [low advisory]`<br>**Do:** `composer update symfony/polyfill-intl-idn` (gets v1.43.0) — clears the advisory<br>*vulnerable:* 1 advisory: 1 low — all fixed on 1.x; advisory: CVE-2026-46644 symfony/polyfill-intl-idn accepts xn-- labels whose Punycode payload decodes to ASCII-only: insecure equivalence<br>*age:* installed v1.37.0, release date not known (no date); 1.x last released v1.43.0 2026-09-23 (less than three weeks ago), its newest release<br>*maintenance accepted (lockrot's list):* polyfills for PHP extensions; released with Symfony and complete for the API of the extension they replace | guzzlehttp/guzzle |

flags: vulnerable 1
depth: 1 of 1 flagged package is named by no line of composer.json · 1 hop 1 · deepest: `symfony/polyfill-intl-idn`, 1 hop (`guzzlehttp/guzzle` › `symfony/polyfill-intl-idn`)
security: 1 vulnerable package · 1 advisory: 1 low
libyears: the one package could not be measured
pulled in by: `guzzlehttp/guzzle` 1
advisories: 1 package asked · packagist.org answered (1 on record for 1 package)
php: the lock installs on PHP 7.2 to 8.5

**Update now:** `composer update symfony/polyfill-intl-idn`

<sub>2 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
