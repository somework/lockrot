### lockrot: 1 of 2 packages flagged — medium 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| medium | `symfony/monolog-bundle` | v3.5.0 | old-promise | `8 = old-promise 16 ÷ 2 transitive`<br>**Do:** composer update 'symfony/monolog-bundle:^3.11.2' (gets v3.6.0, below the newest v3.11.2); not checked: old-promise; Composer's solver checked it against the lock<br>*old-promise:* released 2019-11-13 for PHP 5 (php "\>=5.6"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested<br>*age:* installed v3.5.0 released 2019-11-13 (6.9 years ago); 3.x last released v3.11.2 2026-04-02 (0.5 years ago); newest release v4.1.0 2026-09-10 (0.1 years ago); last push 2026-09-12 (0.1 years ago) | mautic/core-lib |

flags: old-promise 1
depth: 1 of 1 flagged package is named by no line of composer.json · 1 hop 1 · deepest: `symfony/monolog-bundle`, 1 hop (`mautic/core-lib` › `symfony/monolog-bundle`)
libyears: 6.8 behind across the one package · 0.0 from direct requirements · furthest behind symfony/monolog-bundle v3.5.0 at 6.8
pulled in by: `mautic/core-lib` 1
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)
moves: checked with the solver of Composer 2.10.3 against the lock; ext-\* requirements not checked
php: the lock installs on PHP 5.6 to 8.5

- note: 1 package is not from a Composer repository: its release metadata and repository activity were not read

<sub>2 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
