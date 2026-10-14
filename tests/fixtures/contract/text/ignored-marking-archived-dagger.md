### lockrot: 1 of 3 packages flagged — critical 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **critical** | `symfony/debug` | v3.4.47 | abandoned · old-promise | `36 = abandoned 32 + old-promise 4 [¼ of 16]`<br>**Do:** find a replacement (none named)<br>*abandoned:* its repository is archived on GitHub; last release 2022-07-28 (4.2 years ago); last push 2022-07-29 (4.2 years ago)<br>*old-promise:* released 2020-10-24 for PHP 5 (php "^5.5.9\|\>=7.0.8"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested<br>*abandoned marking ignored* (audit.ignore-abandoned: symfony/debug): removed in the 7.x upgrade (constraint ^3.4 not applied, as composer audit does not apply it) | direct |

flags: abandoned 1 · old-promise 1 · 1 package carries more than one
depth: every flagged package is named in composer.json · direct 1
libyears: 1.8 behind across the one package · 1.8 from direct requirements · furthest behind symfony/debug v3.4.47 at 1.8
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)

- note: Composer's abandoned ignore list (config.audit.ignore-abandoned) covers the abandoned marking of 1 package: symfony/debug. lockrot does not count the marking; an archived repository and the release and push dates still count.

<sub>3 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
