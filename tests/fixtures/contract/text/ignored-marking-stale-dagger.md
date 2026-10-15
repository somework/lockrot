### lockrot: 1 of 5 packages flagged — medium 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| medium | `doctrine/cache` | 2.1.1 | stale | `8 = stale 8`<br>**Do:** —<br>*stale:* last release 2022-05-20 (4.4 years ago)<br>*abandoned marking ignored* (policy.abandoned: doctrine/cache): PSR-6 migration is planned for 9.0 | direct |

flags: stale 1
depth: every flagged package is named in composer.json · direct 1
libyears: 0.8 behind across the one package · 0.8 from direct requirements · furthest behind doctrine/cache 2.1.1 at 0.8
advisories: 1 package asked · packagist.org answered (1 on record for 1 package)

- note: Composer's abandoned ignore list (config.policy.abandoned.ignore) covers the abandoned marking of 1 package: doctrine/cache. lockrot does not count the marking; an archived repository and the release and push dates still count.

<sub>5 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
