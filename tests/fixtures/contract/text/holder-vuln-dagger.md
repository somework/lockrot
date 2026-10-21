### lockrot: 1 of 10 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `joomla/string` | 2.0.1 | left-behind | `16 = left-behind 16`<br>**Do:** raise require.php to \>=8.1, then require joomla/string ^3.0 — clears left-behind; 3.x needs php ^8.1.0, require.php allows 7.2.5 (a major raise); the target php 8.4 runs it; held by joomla/filter 2.0.5 (requires ^2.0; itself medium: vulnerable); the packages that hold it may need to move too; a new major brings its own requirements, which lockrot does not solve<br>*left-behind:* branch 2.x last released 2022-08-15 (4.1 years ago); 4.x released 4.0.0 (2025-07-23), needs php ^8.3.0 above the project's php ^7.2.5; no releasing branch within reach | direct |

flags: left-behind 1
depth: every flagged package is named in composer.json · direct 1
libyears: 2.9 behind across the one package · 2.9 from direct requirements · furthest behind joomla/string 2.0.1 at 2.9
advisories: 1 package asked · packagist.org answered (2 on record for 1 package)
php: the lock installs on PHP 7.2 to 7.4, so your target, 8.4, is excluded; require.php excludes 8.0

<sub>10 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
