### lockrot: 1 of 3 packages flagged — critical 1

**gate: fails** · --fail-on=high,unchecked · 1 finding fails it (high 1, unchecked 1) · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **critical** | `symfony/debug` | v3.4.47 | abandoned · old-promise | `36 = abandoned 32 + old-promise 4 [¼ of 16]`<br>**Do:** replace with symfony/error-handler (named by its registry)<br>*abandoned:* marked abandoned by its registry, replacement: symfony/error-handler; last release 2022-07-28 (4.2 years ago)<br>*old-promise:* released 2020-10-24 for PHP 5 (php "^5.5.9\|\>=7.0.8"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested<br>checks missing: S9 (offline) | direct |

flags: abandoned 1 · old-promise 1 · 1 package carries more than one
depth: every flagged package is named in composer.json · direct 1
security: no vulnerable package · advisories not checked
libyears: 1.8 behind across the one package · 1.8 from direct requirements · furthest behind symfony/debug v3.4.47 at 1.8
advisories: 0 packages asked, 1 not asked (offline) · packagist.org not asked (offline)
php: the lock installs on PHP 7.2 to 8.5

- note: offline: repository metadata served from Composer's cache
- note: GitHub unreachable for 1 repositories: not stubbed: https://api.github.com/repos/symfony/debug

<sub>3 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
