### lockrot: 1 of 3 packages flagged — critical 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **critical** | `sensio/framework-extra-bundle` | v6.2.10 | abandoned | `32 = abandoned 32`<br>**Do:** replace it: its registry suggests "Symfony"<br>*abandoned:* marked abandoned by its registry, replacement: Symfony; its repository is archived on GitHub; last release 2023-02-24 (3.6 years ago); last push 2023-02-24 (3.6 years ago)<br>pulls in 1 flagged package: doctrine/annotations (abandoned) | direct |

flags: abandoned 1
depth: every flagged package is named in composer.json · direct 1
libyears: 0.0 behind across the one package
advisories: 1 package asked · packagist.org answered (1 on record for 1 package)

<sub>3 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
