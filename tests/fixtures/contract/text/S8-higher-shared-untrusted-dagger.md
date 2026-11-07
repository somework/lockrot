### lockrot: nothing flagged in 3 packages

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| ok | `pagerfanta/twig` | v3.8.0 | ok | `0`<br>pulls in 1 flagged package: pagerfanta/core (left-behind)<br>checks missing: S2, S8 (undated releases) | direct |

libyears: at least 0.1 behind across the one package · 0.1 from direct requirements · furthest behind `pagerfanta/twig` v3.8.0 at 0.1 or more\
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)\
php: the lock installs on PHP 7.4 to 8.5

<sub>3 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
