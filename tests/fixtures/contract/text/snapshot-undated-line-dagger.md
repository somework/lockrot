### lockrot: 1 of 2 packages flagged — medium 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| medium | `symfony/event-dispatcher` | 4.4.x-dev | pinned | `8 = pinned 16 ÷ 2 transitive`<br>**Do:** move it to a tag — the newest on its own line is v4.4.44, through mautic/core-lib; 4.x needs php \>=7.1.3; not checked: left-behind, old-promise; what requires it must allow it<br>*pinned:* pinned to branch snapshot 4.4.x-dev<br>*age:* newest release v8.1.5 2026-08-21 (0.1 years ago); last push 2026-09-25 (less than three weeks ago) | mautic/core-lib |

flags: pinned 1
depth: 1 of 1 flagged package is named by no line of composer.json · 1 hop 1 · deepest: `symfony/event-dispatcher`, 1 hop (`mautic/core-lib` › `symfony/event-dispatcher`)
libyears: the one package could not be measured
pulled in by: `mautic/core-lib` 1
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)
php: the lock installs on PHP 7.2 to 8.5

- note: 1 package is not from a Composer repository: its release metadata and repository activity were not read

<sub>2 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
