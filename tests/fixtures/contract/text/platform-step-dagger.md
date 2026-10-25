### lockrot: 1 of 8 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `scheb/2fa-bundle` | v5.13.2 | left-behind | `16 = left-behind 16`<br>**Do:** First set config.platform.php to your PHP 8.4 release: the lock records 7.4.29. raise require.php to \>=8.0, then require scheb/2fa-bundle ^6.13 — clears left-behind; it stops below the newest PHP, so capped would count; 6.x needs php \~8.0.0 \|\| \~8.1.0 \|\| \~8.2.0 \|\| \~8.3.0 \|\| \~8.4.0, require.php allows 7.4 (a major raise); the target php 8.4 runs it; a new major brings its own requirements, which lockrot does not solve; not checked with Composer's solver (Composer refuses the lock itself), so it holds only if Composer resolves it<br>*left-behind:* branch 5.x last released 2022-04-16 (4.5 years ago); 8.x released v8.6.1 (2026-07-10), needs php \~8.4.0 \|\| \~8.5.0 above the project's php \>=7.4; no releasing branch within reach | direct |

flags: left-behind 1
depth: every flagged package is named in composer.json · direct 1
libyears: 4.2 behind across the one package · 4.2 from direct requirements · furthest behind scheb/2fa-bundle v5.13.2 at 4.2
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)
moves: checked with the solver of Composer 2.10.3 against the lock; ext-\* requirements not checked
php: the lock installs on PHP 7.4, so your target, 8.4, is excluded; the update of the 2 packages that exclude it is not checked (a package it needs could not be loaded)

- note: 2 packages are not from a Composer repository: their release metadata and repository activity were not read

<sub>8 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
