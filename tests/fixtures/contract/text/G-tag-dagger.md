### lockrot: 1 of 2 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `friendsofsymfony/oauth-server-bundle` | dev-master | pinned · stale | `18 = pinned 16 + stale 2 [¼ of 8]`<br>**Do:** require a tag — the newest is 1.6.2 (2019-01-23); 1.x needs php \>=5.5, which require.php allows; it was released before your target PHP's major, so old-promise would count; stale stays<br>*pinned:* pinned to branch snapshot dev-master<br>*stale:* last release 2019-01-23 (7.7 years ago)<br>pulls in 1 flagged package: friendsofsymfony/oauth2-php (stale) | direct |

flags: pinned 1 · stale 1 · 1 package carries more than one
depth: every flagged package is named in composer.json · direct 1
libyears: the one package could not be measured
advisories: 1 package asked · packagist.org answered (1 on record for 1 package)
php: the lock installs on PHP 7.4 to 8.5

<sub>2 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
