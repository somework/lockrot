### lockrot: 1 of 1 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `symfony/debug` | v3.4.47 | abandoned · old-promise | `18 = (abandoned 32 + old-promise 4 [¼ of 16]) ÷ 2 unreached`<br>**Do:** replace with symfony/error-handler (named by its registry); lockrot found no path from your requirements to it: \`composer why\` names what requires it<br>*abandoned:* marked abandoned by its registry, replacement: symfony/error-handler; its repository is archived on GitHub; last release 2022-07-28 (4.2 years ago); last push 2022-07-29 (4.2 years ago)<br>*old-promise:* released 2020-10-24 for PHP 5 (php "^5.5.9\|\>=7.0.8"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested |  |

flags: abandoned 1 · old-promise 1 · 1 package carries more than one
depth: 1 of 1 flagged package is named by no line of composer.json · unreached 1
libyears: 1.8 behind across the one package · 0.0 from direct requirements · furthest behind symfony/debug v3.4.47 at 1.8
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)

<sub>1 package checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
