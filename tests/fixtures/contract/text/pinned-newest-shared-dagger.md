### lockrot: 1 of 2 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `friendsofsymfony/oauth-server-bundle` | dev-master | pinned | `16 = pinned 16`<br>**Do:** require a tag — the newest is v2.0.0 (2024-01-01); 2.x needs php \>=7.2, which require.php allows<br>*pinned:* pinned to branch snapshot dev-master<br>*age:* last push 2024-01-12 (2.7 years ago)<br>pulls in 1 flagged package: friendsofsymfony/oauth2-php (stale)<br>checks missing: S2 (undated releases) | direct |

flags: pinned 1\
depth: every flagged package is named in composer.json · direct 1\
libyears: the one package could not be measured\
advisories: 1 package asked · packagist.org answered (1 on record for 1 package)\
php: the lock installs on PHP 7.4 to 8.5

<sub>2 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run lockrot with `--format=json` for details.</sub>
