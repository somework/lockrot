### lockrot: 1 of 1 packages flagged — critical 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **critical** | `yosymfony/toml` | v1.0.4 | silent · old-promise | `36 = silent 32 + old-promise 4 [¼ of 16]`<br>**Do:** find a maintained alternative: nothing released for 8.1 years<br>*silent:* last release 2018-08-08 (8.1 years ago); last push 2020-08-08 (6.1 years ago)<br>*old-promise:* released 2018-08-08 for PHP 7 (php "\>=7.1"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested | direct |

flags: silent 1 · old-promise 1 · 1 package carries more than one
depth: every flagged package is named in composer.json · direct 1
libyears: 0.0 behind across the one package
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)

<sub>1 package checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
