### lockrot: 1 of 2 packages flagged — low 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| low | `ocramius/proxy-manager` | 2.2.4 | stale · capped | `5 = (stale 8 + capped 2 [¼ of 8]) ÷ 2 transitive`<br>**Do:** —<br>*stale:* last release 2022-03-05 (4.6 years ago)<br>*capped:* requires php ^7.2.0: it stops at 7.4, so the newest PHP, 8.5, is excluded | symfony/proxy-manager-bridge |

flags: stale 1 · capped 1 · 1 package carries more than one
depth: 1 of 1 flagged package is named by no line of composer.json · 1 hop 1 · deepest: `ocramius/proxy-manager`, 1 hop (`symfony/proxy-manager-bridge` › `ocramius/proxy-manager`)
libyears: 0.0 behind across the one package · 0.0 from direct requirements · furthest behind ocramius/proxy-manager 2.2.4 at 0.0
pulled in by: `symfony/proxy-manager-bridge` 1
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)
php: the lock installs on PHP 7.2 to 7.4, so your target, 8.4, is excluded; `ocramius/proxy-manager` excludes 8.0

<sub>2 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
