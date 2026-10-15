### lockrot: nothing flagged in 6 packages

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| unknown | `drupal/core` | 10.6.18 | unknown | `0`<br>pulls in 3 flagged packages: guzzlehttp/guzzle (vulnerable), pear/console\_getopt (silent), pear/pear\_exception (stale) | direct |

libyears: the one package could not be measured
advisories: 1 package asked · packagist.org answered (127 on record for 2 packages)

- note: 1 package is not from a Composer repository: its release metadata and repository activity were not read

<sub>6 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
