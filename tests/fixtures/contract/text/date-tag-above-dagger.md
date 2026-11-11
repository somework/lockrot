### lockrot: 1 of 1 package flagged — medium 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| medium | `prestashop/ps_contactinfo` | v3.3.2 | vulnerable | `8 = vulnerable 8 [medium advisory]`<br>**Do:** `composer update prestashop/ps_contactinfo` (gets v3.3.3) — clears the advisory<br>*vulnerable:* 1 advisory: 1 medium — all fixed on 3.x; advisory: CVE-2025-24027 ps\_contactinfo has a potential XSS due to usage of the nofilter tag in template<br>*age:* installed v3.3.2 released 2023-03-09 (3.6 years ago); 3.x last released v3.3.3 2025-01-22 (1.7 years ago); newest release 2&#8203;0200101 2025-06-01 (1.3 years ago); last push 2026-08-31 (0.1 years ago) | direct |

flags: vulnerable 1\
depth: every flagged package is named in composer.json · direct 1\
security: 1 vulnerable package · 1 advisory: 1 medium\
libyears: 1.9 behind across the one package · 1.9 from direct requirements · furthest behind `prestashop/ps_contactinfo` v3.3.2 at 1.9\
advisories: 1 package asked · packagist.org answered (1 on record for 1 package)\
php: the lock installs on PHP 7.1 to 7.4; your target, 8.4, is excluded: require.php allows only ^7.1.3

**Update now:** `composer update prestashop/ps_contactinfo`

<sub>1 package checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run lockrot with `--format=json` for details.</sub>
