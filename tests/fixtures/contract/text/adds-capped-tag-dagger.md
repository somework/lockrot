### lockrot: 1 of 1 package flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `matomo/referrer-spam-list` | dev-master | pinned | `16 = pinned 16`<br>**Do:** require a tag — the newest is 4.0.5 (2026-04-15); 4.x needs php \>=7.2 \<8.5, which require.php allows; it stops below the newest PHP, so capped would count<br>*pinned:* pinned to branch snapshot dev-master<br>*age:* newest release 4.0.5 2026-04-15 (0.5 years ago); last push 2026-09-22 (less than three weeks ago) | direct |

flags: pinned 1
depth: every flagged package is named in composer.json · direct 1
libyears: the one package could not be measured
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)
php: the lock installs on PHP 7.2 to 8.5

<sub>1 package checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
