### lockrot: 1 of 2 packages flagged — low 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| low | `ocramius/proxy-manager` | 2.2.4 | capped | `4 = capped 8 ÷ 2 transitive`<br>**Do:** no move: no release above 2.2.4 admits PHP 8.5<br>*capped:* requires php ^7.2.0: it stops at 7.4, so the newest PHP, 8.5, is excluded<br>*age:* installed 2.2.4 released 2022-03-05 (4.6 years ago); last push 2026-09-25 (less than three weeks ago)<br>checks missing: S2 (undated releases) | symfony/proxy-manager-bridge |

flags: capped 1\
depth: 1 of 1 flagged package is named by no line of composer.json · 1 hop 1 · deepest: `ocramius/proxy-manager`, 1 hop (`symfony/proxy-manager-bridge` › `ocramius/proxy-manager`)\
libyears: at least 0.0 behind across the one package · 0.0 from direct requirements · furthest behind `ocramius/proxy-manager` 2.2.4 at 0.0 or more\
pulled in by: `symfony/proxy-manager-bridge` 1\
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)\
php: the lock installs on PHP 7.2 to 7.4, so your target, 8.4, is excluded; the update of the package that excludes it is not checked (the run checked no move)

<sub>2 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
