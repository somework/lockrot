### lockrot: 1 of 4 packages flagged — critical 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **critical** | `guzzlehttp/cache-subscriber` | 0.2.0 | abandoned · old-promise | `36 = abandoned 32 + old-promise 4 [¼ of 16]`<br>**Do:** find a replacement (none named)<br>*abandoned:* marked abandoned by its registry; its repository is archived on GitHub; last release 2019-09-16 (7.0 years ago); last push 2019-09-16 (7.0 years ago)<br>*old-promise:* released 2019-09-16 for PHP 5 (php "\>=5.4.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested<br>pulls in 2 flagged packages: guzzlehttp/ringphp (abandoned), guzzlehttp/streams (abandoned) | direct |

flags: abandoned 1 · old-promise 1 · 1 package carries more than one
depth: every flagged package is named in composer.json · direct 1
libyears: 0.0 behind across the one package
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)

<sub>4 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
