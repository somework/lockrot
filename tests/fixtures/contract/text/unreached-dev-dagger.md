### lockrot: 1 of 1 packages flagged — medium 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| medium | `bacon/bacon-qr-code` | 2.0.8 | left-behind | `8 = left-behind 16 ÷ 2 unreached`<br>**Do:** raise require.php to \>=8.1, then move bacon/bacon-qr-code to ^3.1 — clears left-behind; 3.x needs php ^8.1, require.php allows 7.2.5 (a major raise); the target php 8.4 runs it; a new major brings its own requirements, which lockrot does not solve; lockrot found no path from your requirements to it, so what requires it must allow it<br>*left-behind:* branch 2.x last released 2022-12-07 (3.8 years ago); 3.x released v3.1.1 (2026-04-05), needs php ^8.1 above the project's php ^7.2.5 \|\| ^8.0; no releasing branch within reach |  |

flags: left-behind 1
depth: 1 of 1 flagged package is named by no line of composer.json · unreached 1 · 0 in packages-dev
libyears: 3.3 behind across the one package · 0.0 from direct requirements · furthest behind bacon/bacon-qr-code 2.0.8 at 3.3
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)
php: the lock installs on PHP 7.2 to 8.5

<sub>1 package checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
