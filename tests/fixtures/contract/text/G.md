### lockrot: 1 of 1 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `friendsofsymfony/oauth-server-bundle` | dev-master | pinned · stale | `18 = pinned 16 + stale 2 [¼ of 8]`<br>**Do:** no move runs on the target: the newest tag, 1.6.2, needs php ^5.5\|^7.0 and the target php 8.4 does not run it; replace it<br>*pinned:* pinned to branch snapshot dev-master<br>*stale:* last release 2019-01-23 (7.7 years ago) | direct |

flags: pinned 1 · stale 1 · 1 package carries more than one
depth: every flagged package is named in composer.json · direct 1
libyears: the one package could not be measured
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)

<sub>1 package checked — data as of 2026-10-14 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
