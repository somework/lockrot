### lockrot: 1 of 2 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `friendsofsymfony/oauth-server-bundle` | dev-master | pinned | `16 = pinned 16`<br>**Do:** require a tag — the newest is v4.0.0+x\|calc (2025-01-01); 4.x needs php \>=7.2, which require.php allows; lockrot prints no command, because its release name holds \|, \< or \><br>*pinned:* pinned to branch snapshot dev-master<br>*age:* newest release v4.0.0+x\|calc 2025-01-01 (1.7 years ago); last push 2024-01-12 (2.7 years ago)<br>pulls in 1 flagged package: friendsofsymfony/oauth2-php (stale) | direct |

flags: pinned 1\
depth: every flagged package is named in composer.json · direct 1\
libyears: the one package could not be measured\
advisories: 1 package asked · packagist.org answered (1 on record for 1 package)\
php: the lock installs on PHP 7.4 to 8.5

- note: lockrot prints no command for 1 move: a release name holds \|, \< or \>, which cmd.exe and PowerShell run or redirect

<sub>2 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
