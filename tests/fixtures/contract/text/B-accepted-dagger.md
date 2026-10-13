### lockrot: 1 of 1 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `symfony/monolog-bridge` | v2.6.13 | vulnerable · left-behind (accepted) · old-promise (accepted) | `16 = vulnerable 16 [high advisory] (left-behind, old-promise accepted)`<br>**Do:** require symfony/monolog-bridge ^5.4.52 — clears the advisory; 5.x needs php \>=7.2.5, which require.php allows; the target php 8.4 runs it; a new major brings its own requirements, which lockrot does not solve<br>*vulnerable:* 1 advisory: 1 high — none fixed on 2.x; advisory: CVE-2026-45077 Unauthenticated PHP Object Deserialization in MonologBridge server:log Listener | direct |

flags: vulnerable 1
depth: every flagged package is named in composer.json · direct 1
security: 1 vulnerable package · 1 advisory: 1 high
libyears: 10.6 behind across the one package · 10.6 from direct requirements · furthest behind symfony/monolog-bridge v2.6.13 at 10.6
advisories: 1 package asked · packagist.org answered (1 on record for 1 package)

**Update now:** `composer update symfony/monolog-bridge`

- note: extra.lockrot.ignore\[\] accepts maintenance flags only, not advisories: symfony/monolog-bridge: 1 counted advisory; accept them in Composer's audit policy if that is the decision

<sub>1 package checked — data as of 2026-10-14 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
