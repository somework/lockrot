### lockrot: nothing flagged in 4 packages

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| finished | `laminas/laminas-code` | 4.7.1 | finished · capped (accepted) | `0 (capped accepted)` | direct |

libyears: 2.9 behind across the one package · 2.9 from direct requirements · furthest behind laminas/laminas-code 4.7.1 at 2.9
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)
php: the lock installs on PHP 7.4 to 8.1, so your target, 8.4, is excluded; the update of the package that excludes it is not checked (the run checked no move)

<sub>4 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
