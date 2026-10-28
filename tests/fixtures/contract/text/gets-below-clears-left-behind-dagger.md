### lockrot: 1 of 1 package flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `symfony/google-mailer` | v4.4.41 | left-behind | `16 = left-behind 16`<br>**Do:** require symfony/google-mailer ^5.4 (gets v5.4.19, below the newest v5.4.45) — clears left-behind; 5.x needs php \>=7.2.5, which require.php allows; the target php 8.4 runs it; a new major brings its own requirements; Composer's solver checked it against the lock<br>*left-behind:* branch 4.x last released 2022-04-12 (4.5 years ago); 8.x released v8.1.0 (2026-05-29), needs php \>=8.4.1 above the project's php \>=7.4; 5.x released v5.4.45 (2024-09-25) | direct |

flags: left-behind 1
depth: every flagged package is named in composer.json · direct 1
libyears: 4.1 behind across the one package · 4.1 from direct requirements · furthest behind symfony/google-mailer v4.4.41 at 4.1
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)
moves: checked with the solver of Composer 2.10.3 against the lock; ext-\*, lib-\*, php-debug, php-ipv6 and php-zts requirements not checked
php: the lock installs on PHP 7.4 to 8.5

<sub>1 package checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
