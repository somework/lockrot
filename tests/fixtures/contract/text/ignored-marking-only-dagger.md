### lockrot: nothing flagged in 2 packages

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| ok | `doctrine/annotations` | 2.0.2 | ok | `0`<br>*abandoned marking ignored* (policy.abandoned: doctrine/\*): the annotations reader goes with the ORM 3 upgrade | mautic/core-lib |

libyears: 0.0 behind across the one package
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)

- note: Composer's abandoned ignore list (config.policy.abandoned.ignore) covers the abandoned marking of 1 package: doctrine/annotations. lockrot does not count the marking; an archived repository and the release and push dates still count.

<sub>2 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
