### lockrot: 1 of 7 packages flagged — critical 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **critical** | `guzzlehttp/guzzle` | 5.3.4 | left-behind · old-promise · vulnerable | `36 = left-behind 16 + old-promise 4 [¼ of 16] + vulnerable 16 [high advisory]`<br>**Do:** raise require.php to \>=7.2.5, then require guzzlehttp/guzzle ^7.15.2 — clears left-behind and 14 advisories; 7.x needs php ^7.2.5 \|\| ^8.0, require.php allows 7.1.3 (a minor raise); the target php 8.4 runs it; not checked: old-promise; held by composer.json (requires \~5.0), guzzlehttp/cache-subscriber 0.2.0 (requires \~5.0; itself abandoned), csa/guzzle-bundle dev-compat-php (requires ^5.3; itself pinned), guzzlehttp/log-subscriber 1.0.1 (requires \~4.0 \| \~5.0; itself abandoned) and prestashop/circuit-breaker v3.0.0 (requires ^5; itself left-behind); the packages that hold it may need to move too; a new major brings its own requirements, which lockrot does not solve<br>*left-behind:* branch 5.x last released 2019-10-30 (6.9 years ago); 8.x released 8.2.0 (2026-09-06), needs php ^7.4 \|\| ^8.0 above the project's php ^7.1.3; no releasing branch within reach<br>*old-promise:* released 2019-10-30 for PHP 5 (php "\>=5.4.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested<br>*vulnerable:* 14 advisories: 6 high, 8 medium — none fixed on 5.x; worst: CVE-2022-31043 Fix failure to strip Authorization header on HTTP downgrade<br>pulls in 2 flagged packages: guzzlehttp/ringphp (abandoned · old-promise), guzzlehttp/streams (abandoned · old-promise) | direct |

flags: left-behind 1 · old-promise 1 · vulnerable 1 · 1 package carries more than one
depth: every flagged package is named in composer.json · direct 1
security: 1 vulnerable package · 14 advisories: 6 high, 8 medium
libyears: 6.9 behind across the one package · 6.9 from direct requirements · furthest behind guzzlehttp/guzzle 5.3.4 at 6.9
advisories: 1 package asked · packagist.org answered (15 on record for 1 package)
php: the lock installs on PHP 7.1 to 7.4; your target, 8.4, is excluded: require.php allows only ^7.1.3

- note: 1 package is not from a Composer repository: its release metadata and repository activity were not read

<sub>7 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
