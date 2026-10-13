### lockrot: 1 of 3 packages flagged — critical 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **critical** | `guzzlehttp/guzzle` | 5.3.4 | left-behind · old-promise · vulnerable | `36 = left-behind 16 + old-promise 4 [¼ of 16] + vulnerable 16 [high advisory]`<br>**Do:** require guzzlehttp/guzzle ^8.2 — clears left-behind, old-promise and 14 advisories; 8.x needs php ^7.4 \|\| ^8.0, which require.php allows; the target php 8.4 runs it; a new major brings its own requirements, which lockrot does not solve<br>*left-behind:* branch 5.x last released 2019-10-30 (7.0 years ago); 8.x released 8.2.0 (2026-09-06)<br>*old-promise:* released 2019-10-30 for PHP 5 (php "\>=5.4.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested<br>*vulnerable:* 14 advisories: 6 high, 8 medium — none fixed on 5.x; worst: CVE-2022-31043 Fix failure to strip Authorization header on HTTP downgrade | direct |

flags: left-behind 1 · old-promise 1 · vulnerable 1 · 1 package carries more than one
depth: every flagged package is named in composer.json · direct 1
security: 1 vulnerable package · 14 advisories: 6 high, 8 medium
libyears: 6.9 behind across the one package · 6.9 from direct requirements · furthest behind guzzlehttp/guzzle 5.3.4 at 6.9
advisories: 1 package asked · packagist.org answered (14 on record for 1 package)

**Update now:** `composer update guzzlehttp/guzzle`

<sub>3 packages checked — data as of 2026-10-14 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
