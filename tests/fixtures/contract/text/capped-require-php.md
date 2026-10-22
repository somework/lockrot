### lockrot: 1 of 1 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `pelago/emogrifier` | v2.2.0 | left-behind · capped | `18 = left-behind 16 + capped 2 [¼ of 8]`<br>**Do:** raise require.php to \>=7.3, then require pelago/emogrifier ^7.3 — clears left-behind and capped; 7.x needs php \~7.3.0 \|\| \~7.4.0 \|\| \~8.0.0 \|\| \~8.1.0 \|\| \~8.2.0 \|\| \~8.3.0 \|\| \~8.4.0, require.php allows 7.1.3 (a minor raise); the target php 8.4 runs it; a new major brings its own requirements, which lockrot does not solve<br>*left-behind:* branch 2.x last released 2019-09-04 (7.1 years ago); 8.x released v8.2.0 (2025-11-27), needs php \~7.3.0 \|\| \~7.4.0 \|\| \~8.0.0 \|\| \~8.1.0 \|\| \~8.2.0 \|\| \~8.3.0 \|\| \~8.4.0 \|\| \~8.5.0 above the project's php ^7.1.3; no releasing branch within reach<br>*capped:* requires php ^5.5.0 \|\| \~7.0.0 \|\| \~7.1.0 \|\| \~7.2.0 \|\| \~7.3.0: it stops at 7.3, so 7.4, the newest PHP that require.php allows, is excluded | direct |

flags: left-behind 1 · capped 1 · 1 package carries more than one
depth: every flagged package is named in composer.json · direct 1
libyears: 6.2 behind across the one package · 6.2 from direct requirements · furthest behind pelago/emogrifier v2.2.0 at 6.2
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)
php: the lock installs on PHP 7.1 to 7.3, so your target, 8.4, is excluded; `pelago/emogrifier` excludes 7.4

<sub>1 package checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
