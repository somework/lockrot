### lockrot: 1 of 35 packages flagged — medium 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| medium | `symfony/var-dumper` | v4.4.47 | left-behind | `8 = left-behind 16 ÷ 2 transitive`<br>**Do:** move symfony/var-dumper to ^5.4 through babdev/pagerfanta-bundle, bdunogier/guzzle-site-authenticator, craue/config-bundle and 31 more — clears left-behind; 5.x needs php \>=7.2.5, which require.php allows; the target php 8.4 runs it; a new major brings its own requirements, which lockrot does not solve; what requires it must allow it; the newest release of symfony/error-handler (v8.1.8) still requires it<br>*left-behind:* branch 4.x last released 2022-10-03 (4.0 years ago); 8.x released v8.1.7 (2026-09-03), needs php \>=8.4.1 above the project's php \>=7.4; 5.x released v5.4.48 (2024-11-08) | babdev/pagerfanta-bundle, bdunogier/guzzle-site-authenticator, craue/config-bundle and 31 more |

flags: left-behind 1
depth: every flagged package is named in composer.json · 1 hop 1 · deepest: `symfony/var-dumper`, 1 hop (`symfony/error-handler` › `symfony/var-dumper`) · 1 more is named only in require-dev, which this run did not walk (pass --dev)
libyears: 3.9 behind across the one package · 0.0 from direct requirements · furthest behind symfony/var-dumper v4.4.47 at 3.9
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)
php: the lock installs on PHP 7.4 to 8.5

<sub>35 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
