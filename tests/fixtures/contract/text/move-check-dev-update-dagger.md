### lockrot: 1 of 3 packages flagged — medium 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| medium | `guzzlehttp/psr7` | 2.8.0 | vulnerable | `8 = vulnerable 8 [medium advisory]`<br>**Do:** `composer update 'guzzlehttp/psr7:^2.12.3'` — clears 4 advisories; Composer's solver installs 2.x-dev, a dev version, not 2.13.1, so this command does not reach a release<br>*vulnerable:* 4 advisories: 4 medium — all fixed on 2.x; worst: CVE-2026-55766 CRLF injection in HTTP start-line serialization<br>*age:* installed 2.8.0 released 2025-08-23 (1.1 years ago); 2.x last released 2.13.1 2026-08-24 (0.1 years ago); newest release 3.1.0 2026-08-24 (0.1 years ago); last push 2026-08-24 (0.1 years ago) | direct |

flags: vulnerable 1\
depth: every flagged package is named in composer.json · direct 1\
security: 1 vulnerable package · 4 advisories: 4 medium\
libyears: 1.0 behind across the one package · 1.0 from direct requirements · furthest behind `guzzlehttp/psr7` 2.8.0 at 1.0\
advisories: 1 package asked · packagist.org answered (6 on record for 1 package)\
moves: checked with the solver of Composer 2.10.3 against the lock; ext-\*, lib-\*, php-debug, php-ipv6 and php-zts requirements not checked\
php: the lock installs on PHP 7.4 to 8.5

<sub>3 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run lockrot with `--format=json` for details.</sub>
