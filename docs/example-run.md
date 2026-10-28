---
title: Example run — lockrot on wallabag's composer.lock
description: "A recorded lockrot run on wallabag's public composer.lock: the full table output, the same lock as JSON, and a clean run to compare it with."
---

# Example run

What lockrot prints on a real lock, and how to read it. Recorded on 2026-10-26 with lockrot 0.14.0
on a snapshot of [wallabag](https://github.com/wallabag/wallabag)'s public `composer.lock`, with
`GITHUB_TOKEN` set, so no repository check was capped, and `COLUMNS=120`, so the wrapping is fixed.

```bash
COLUMNS=120 php lockrot.phar --target-php=8.4 --output=json:lockrot.json
```

On your project: `composer lockrot`, or `php lockrot.phar -d path/to/project`.

```text
critical (3)
  javibravo/simpleue 2.1.0  direct  ·  silent · old-promise
      36 = silent 32 + old-promise 4 [¼ of 16]
      do: find a maintained alternative: nothing released for 8.9 years
      silent       last release 2017-11-15 (8.9 years ago); last push 2017-11-18 (8.9 years ago)
      old-promise  released 2017-11-15 for PHP 5 (php ">=5.5"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4
                   untested
  mnapoli/piwik-twig-extension 3.0.0  direct  ·  silent · old-promise
      36 = silent 32 + old-promise 4 [¼ of 16]
      do: find a maintained alternative: nothing released for 6.5 years
      silent       last release 2020-04-24 (6.5 years ago); last push 2020-04-28 (6.5 years ago)
      old-promise  released 2020-04-24 for PHP 7 (php ">=7.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4
                   untested
  sensio/framework-extra-bundle v6.2.10  direct  ·  abandoned
      32 = abandoned 32
      do: replace it: its registry suggests "Symfony"
      abandoned    marked abandoned by its registry, replacement: Symfony; its repository is archived on GitHub; last
                   release 2023-02-24 (3.7 years ago); last push 2023-02-24 (3.7 years ago)
      pulls in 1 flagged package: doctrine/annotations (abandoned)

high (39)
  spomky-labs/otphp v10.0.3  via scheb/2fa-google-authenticator  ·  left-behind · vulnerable
      24 = left-behind 16 ÷ 2 transitive + vulnerable 16 [high advisory]
      do: move spomky-labs/otphp to ^11.4.3 through scheb/2fa-google-authenticator — clears left-behind and 2
          advisories; 11.x needs php >=8.1, which require.php allows; the target php 8.4 runs it; held by composer.json
          (requires scheb/2fa-bundle ^5.13.2) and scheb/2fa-google-authenticator v5.13.2 (requires ^9.1|^10.0; itself
          left-behind) and scheb/2fa-google-authenticator 5.x-dev (requires scheb/2fa-bundle self.version (5.x-dev));
          the packages that hold it may need to move too; a new major brings its own requirements; Composer's solver
          refuses it against the lock
      left-behind  branch 10.x last released 2022-03-17 (4.6 years ago); 11.x released 11.5.0 (2026-06-06)
      vulnerable   2 advisories: 1 high, 1 medium — none fixed on 10.x; worst: PKSA-kbc7-dq62-pt7d Unbounded digits
                   parameter in a provisioning URI triggers an uncaught DivisionByZeroError in OTP generation
  j0k3r/httplug-ssrf-plugin v3.0.1  via j0k3r/graby  ·  vulnerable
      16 = vulnerable 16 [unrated advisory 8 × 2: no reachable fix]
      do: no release fixes it
      vulnerable   1 advisory: 1 unrated — none fixed on 3.x; advisory: PKSA-px1b-j2dd-31r6 Server-side Request
                   Forgery (SSRF)
      age: installed v3.0.1 released 2025-01-06 (1.8 years ago), its newest release; last push 2026-10-19 (less
           than three weeks ago)
  friendsofsymfony/oauth-server-bundle dev-master  direct  ·  pinned · stale
      18 = pinned 16 + stale 2 [¼ of 8]
      do: no move runs on the target: the newest tag, 1.6.2, needs php ^5.5|^7.0 and the target php 8.4 does not run it;
          replace it
      pinned       pinned to branch snapshot dev-master
      stale        last release 2019-01-23 (7.8 years ago)
      pulls in 2 flagged packages: symfony/security-guard (abandoned), friendsofsymfony/oauth2-php (stale)
  mgargano/simplehtmldom 1.5  direct  ·  old-promise · stale
      18 = old-promise 16 + stale 2 [¼ of 8]
      do: test it on PHP 8.4 — 1.5 is its newest release
      old-promise  released 2014-01-05 for PHP 5 (php ">=5.3.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4
                   untested
      stale        last release 2014-01-05 (12.8 years ago); last push 2022-08-04 (4.2 years ago)
  grandt/binstring 1.0.0  via wallabag/phpepub  ·  silent · old-promise
      18 = (silent 32 + old-promise 4 [¼ of 16]) ÷ 2 transitive
      do: find a maintained alternative: nothing released for 11.2 years; it comes in through wallabag/phpepub
          (grandt/phpresizegif requires it): replace wallabag/phpepub
      silent       last release 2015-08-13 (11.2 years ago); last push 2015-08-13 (11.2 years ago)
      old-promise  released 2015-08-13 for PHP 5 (php ">=5.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4
                   untested
  grandt/phpresizegif 1.0.3  via wallabag/phpepub  ·  silent · old-promise
      18 = (silent 32 + old-promise 4 [¼ of 16]) ÷ 2 transitive
      do: find a maintained alternative: nothing released for 11.5 years; it comes in through wallabag/phpepub: replace
          wallabag/phpepub; the newest release of wallabag/phpepub (4.0.10) still requires it
      silent       last release 2015-05-10 (11.5 years ago); last push 2015-08-13 (11.2 years ago)
      old-promise  released 2015-05-10 for PHP 5 (php ">=5.3.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4
                   untested
  grandt/phpzipmerge 1.0.4  via wallabag/phpepub  ·  silent · old-promise
      18 = (silent 32 + old-promise 4 [¼ of 16]) ÷ 2 transitive
      do: find a maintained alternative: nothing released for 11.2 years; it comes in through wallabag/phpepub
          (phpzip/phpzip requires it): replace wallabag/phpepub
      silent       last release 2015-08-18 (11.2 years ago); last push 2015-08-18 (11.2 years ago)
      old-promise  released 2015-08-18 for PHP 5 (php ">=5.3.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4
                   untested
  grandt/relativepath 1.0.2  via wallabag/phpepub  ·  silent · old-promise
      18 = (silent 32 + old-promise 4 [¼ of 16]) ÷ 2 transitive
      do: find a maintained alternative: nothing released for 11.5 years; it comes in through wallabag/phpepub: replace
          wallabag/phpepub; the newest release of wallabag/phpepub (4.0.10) still requires it
      silent       last release 2015-05-14 (11.5 years ago); last push 2020-04-01 (6.6 years ago)
      old-promise  released 2015-05-14 for PHP 5 (php ">=5.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4
                   untested
  hoa/consistency 1.17.05.02  via wallabag/rulerz, wallabag/rulerz-bundle  ·  abandoned · old-promise
      18 = (abandoned 32 + old-promise 4 [¼ of 16]) ÷ 2 transitive
      do: find a replacement (none named); it comes in through wallabag/rulerz, wallabag/rulerz-bundle: replace or
          update wallabag/rulerz, wallabag/rulerz-bundle
      abandoned    marked abandoned by its registry; its repository is archived on GitHub; last release 2017-08-29 (9.2
                   years ago); last push 2021-04-28 (5.5 years ago)
      old-promise  released 2017-05-02 for PHP 5 (php ">=5.5.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4
                   untested
  phpzip/phpzip 2.0.8  via wallabag/phpepub  ·  silent · old-promise
      18 = (silent 32 + old-promise 4 [¼ of 16]) ÷ 2 transitive
      do: find a maintained alternative: nothing released for 10.9 years; it comes in through wallabag/phpepub: replace
          wallabag/phpepub; the newest release of wallabag/phpepub (4.0.10) still requires it
      silent       last release 2015-11-16 (10.9 years ago); last push 2015-11-16 (10.9 years ago)
      old-promise  released 2015-11-16 for PHP 5 (php ">=5.3.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4
                   untested
  pragmarx/random v0.2.2  via pragmarx/recovery  ·  silent · old-promise
      18 = (silent 32 + old-promise 4 [¼ of 16]) ÷ 2 transitive
      do: find a maintained alternative: nothing released for 8.9 years; it comes in through pragmarx/recovery: replace
          pragmarx/recovery; the newest release of pragmarx/recovery (v0.2.1) still requires it
      silent       last release 2017-11-21 (8.9 years ago); last push 2017-12-18 (8.9 years ago)
      old-promise  released 2017-11-21 for PHP 7 (php ">=7.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4
                   untested
  craue/config-bundle 2.7.0  direct  ·  left-behind
      16 = left-behind 16
      do: require craue/config-bundle ^3.0 — clears left-behind; 3.x needs php ^8.2, which require.php allows; the
          target php 8.4 runs it; held by composer.json (requires ^2.7.0); a new major brings its own requirements;
          Composer's solver checked it against the lock
      left-behind  branch 2.x last released 2023-08-06 (3.2 years ago); 3.x released 3.0.0 (2026-09-15)
      pulls in 1 flagged package: doctrine/cache (abandoned)
  doctrine/event-manager 1.2.0  direct  ·  left-behind
      16 = left-behind 16
      do: require doctrine/event-manager ^2.1 — clears left-behind; 2.x needs php ^8.1, which require.php allows; the
          target php 8.4 runs it; held by composer.json (requires ^1.2) and symfony/doctrine-bridge v5.4.48 (requires
          ~1.0) and doctrine/doctrine-fixtures-bundle 3.7.1 (requires symfony/doctrine-bridge ^5.4.48 || ^6.4.16 ||
          ^7.1.9); the packages that hold it may need to move too; a new major brings its own requirements; Composer's
          solver refuses it against the lock
      left-behind  branch 1.x last released 2022-10-12 (4.0 years ago); 2.x released 2.1.1 (2026-01-29)
  lcobucci/jwt 4.3.0  direct  ·  left-behind
      16 = left-behind 16
      do: require lcobucci/jwt ^5.6 — clears left-behind; 5.x needs php ~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0, which
          require.php allows; the target php 8.4 runs it; held by composer.json (requires ^4.3) and composer.json
          (requires scheb/2fa-bundle ^5.13.2) and scheb/2fa-trusted-device v5.13.2 (requires ^3.4|^4.0; itself
          left-behind) and scheb/2fa-trusted-device 5.x-dev (requires scheb/2fa-bundle self.version (5.x-dev)); the
          packages that hold it may need to move too; a new major brings its own requirements; Composer's solver refuses
          it against the lock
      left-behind  branch 4.x last released 2023-01-02 (3.8 years ago); 5.x released 5.6.1 (2026-10-10)
      pulls in 1 flagged package: lcobucci/clock (capped)
  scheb/2fa-backup-code v5.13.2  direct  ·  left-behind
      16 = left-behind 16
      do: require scheb/2fa-backup-code ^7.14 — clears left-behind; 7.x needs php ~8.2.0 || ~8.3.0 || ~8.4.0 ||
          ~8.5.0, which require.php allows; the target php 8.4 runs it; held by composer.json (requires ^5.13.2) and
          composer.json (requires scheb/2fa-bundle ^5.13.2) and scheb/2fa-backup-code v7.14.0, its own release (requires
          scheb/2fa-bundle self.version (v7.14.0)); the packages that hold it may need to move too; a new major brings
          its own requirements; Composer's solver refuses it against the lock
      left-behind  branch 5.x last released 2022-01-03 (4.8 years ago); 7.x released v7.14.0 (2026-01-24)
      pulls in 1 flagged package: symfony/security-guard (abandoned)
      checks missing: S2 (undated releases)
  scheb/2fa-bundle v5.13.2  direct  ·  left-behind
      16 = left-behind 16
      do: require scheb/2fa-bundle ^7.14 — clears left-behind; 7.x needs php ~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0,
          which require.php allows; the target php 8.4 runs it; held by composer.json (requires ^5.13.2) and
          composer.json (requires symfony/config ^5.4.46) and composer.json (requires symfony/dependency-injection
          ^5.4.48) and composer.json (requires symfony/event-dispatcher ^5.4.45) and composer.json (requires
          symfony/framework-bundle ^5.4.45) and composer.json (requires symfony/http-foundation ^5.4.48) and
          composer.json (requires symfony/http-kernel ^5.4.48) and composer.json (requires symfony/security-bundle
          ^5.4.45) and composer.json (requires symfony/twig-bundle ^5.4.45) and scheb/2fa-bundle v7.14.0, its own
          release (requires symfony/config ^6.4 || ^7.0) and scheb/2fa-bundle v7.14.0, its own release (requires
          symfony/dependency-injection ^6.4 || ^7.0) and scheb/2fa-bundle v7.14.0, its own release (requires
          symfony/event-dispatcher ^6.4 || ^7.0) and scheb/2fa-bundle v7.14.0, its own release (requires
          symfony/framework-bundle ^6.4 || ^7.0) and scheb/2fa-bundle v7.14.0, its own release (requires
          symfony/http-foundation ^6.4 || ^7.0) and scheb/2fa-bundle v7.14.0, its own release (requires
          symfony/http-kernel ^6.4 || ^7.0) and scheb/2fa-bundle v7.14.0, its own release (requires
          symfony/property-access ^6.4 || ^7.0) and scheb/2fa-bundle v7.14.0, its own release (requires
          symfony/security-bundle ^6.4 || ^7.0) and scheb/2fa-bundle v7.14.0, its own release (requires
          symfony/twig-bundle ^6.4 || ^7.0) and scheb/2fa-backup-code v5.13.2 (requires self.version (v5.13.2); itself
          left-behind) and scheb/2fa-email v5.13.2 (requires self.version (v5.13.2); itself left-behind) and
          scheb/2fa-google-authenticator v5.13.2 (requires self.version (v5.13.2); itself left-behind) and
          scheb/2fa-trusted-device v5.13.2 (requires self.version (v5.13.2); itself left-behind); the packages that hold
          it may need to move too; a new major brings its own requirements; Composer's solver refuses it against the
          lock
      left-behind  branch 5.x last released 2022-04-16 (4.5 years ago); 8.x released v8.6.1 (2026-07-10), needs php
                   ~8.4.0 || ~8.5.0 above the project's php >=8.2; 7.x released v7.14.0 (2026-06-12)
      pulls in 1 flagged package: symfony/security-guard (abandoned)
  scheb/2fa-email v5.13.2  direct  ·  left-behind
      16 = left-behind 16
      do: require scheb/2fa-email ^7.14 — clears left-behind; 7.x needs php ~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0,
          which require.php allows; the target php 8.4 runs it; held by composer.json (requires ^5.13.2) and
          composer.json (requires scheb/2fa-bundle ^5.13.2) and scheb/2fa-email v7.14.0, its own release (requires
          scheb/2fa-bundle self.version (v7.14.0)); the packages that hold it may need to move too; a new major brings
          its own requirements; Composer's solver refuses it against the lock
      left-behind  branch 5.x last released 2022-01-03 (4.8 years ago); 7.x released v7.14.0 (2026-06-08)
      pulls in 1 flagged package: symfony/security-guard (abandoned)
  scheb/2fa-google-authenticator v5.13.2  direct  ·  left-behind
      16 = left-behind 16
      do: require scheb/2fa-google-authenticator ^7.14 — clears left-behind; 7.x needs php ~8.2.0 || ~8.3.0 || ~8.4.0
          || ~8.5.0, which require.php allows; the target php 8.4 runs it; held by composer.json (requires ^5.13.2) and
          composer.json (requires scheb/2fa-bundle ^5.13.2) and scheb/2fa-google-authenticator v7.14.0, its own release
          (requires scheb/2fa-bundle self.version (v7.14.0)) and scheb/2fa-google-authenticator v7.14.0, its own release
          (requires spomky-labs/otphp ^11.0); the packages that hold it may need to move too; a new major brings its own
          requirements; Composer's solver refuses it against the lock
      left-behind  branch 5.x last released 2022-01-03 (4.8 years ago); 7.x released v7.14.0 (2026-01-24)
      pulls in 3 flagged packages: spomky-labs/otphp (left-behind · vulnerable), symfony/security-guard (abandoned),
      thecodingmachine/safe (left-behind)
      checks missing: S2 (undated releases)
  scheb/2fa-trusted-device v5.13.2  direct  ·  left-behind
      16 = left-behind 16
      do: require scheb/2fa-trusted-device ^7.14 — clears left-behind; 7.x needs php ~8.2.0 || ~8.3.0 || ~8.4.0 ||
          ~8.5.0, which require.php allows; the target php 8.4 runs it; held by composer.json (requires ^5.13.2) and
          composer.json (requires lcobucci/jwt ^4.3) and composer.json (requires scheb/2fa-bundle ^5.13.2) and
          scheb/2fa-trusted-device v7.14.0, its own release (requires lcobucci/jwt ^5.0) and scheb/2fa-trusted-device
          v7.14.0, its own release (requires scheb/2fa-bundle self.version (v7.14.0)); the packages that hold it may
          need to move too; a new major brings its own requirements; Composer's solver refuses it against the lock
      left-behind  branch 5.x last released 2022-01-03 (4.8 years ago); 7.x released v7.14.0 (2026-01-24)
      pulls in 2 flagged packages: symfony/security-guard (abandoned), lcobucci/clock (capped)
      checks missing: S2 (undated releases)
  symfony/webpack-encore-bundle v1.17.2  direct  ·  left-behind
      16 = left-behind 16
      do: require symfony/webpack-encore-bundle ^2.4 — clears left-behind; 2.x needs php >=8.1.0, which require.php
          allows; the target php 8.4 runs it; held by composer.json (requires ^1.17.2); a new major brings its own
          requirements; Composer's solver checked it against the lock
      left-behind  branch 1.x last released 2023-09-26 (3.1 years ago); 2.x released 2.4.2 (2026-09-17)
  wallabag/rulerz dev-master  direct  ·  pinned
      16 = pinned 16
      do: no tagged release to move to; review the snapshot, or replace it
      pinned       pinned to branch snapshot dev-master
      age: last push 2023-12-24 (2.8 years ago)
      pulls in 14 flagged packages: hoa/consistency (abandoned · old-promise), hoa/compiler (abandoned), hoa/event
      (abandoned), hoa/exception (abandoned), hoa/file (abandoned) and 9 more
  wallabag/rulerz-bundle dev-master  direct  ·  pinned
      16 = pinned 16
      do: no tagged release to move to; review the snapshot, or replace it
      pinned       pinned to branch snapshot dev-master
      age: last push 2023-12-24 (2.8 years ago)
      pulls in 15 flagged packages: hoa/consistency (abandoned · old-promise), hoa/compiler (abandoned), hoa/event
      (abandoned), hoa/exception (abandoned), hoa/file (abandoned) and 10 more
  behat/transliterator v1.5.0  via stof/doctrine-extensions-bundle  ·  abandoned
      16 = abandoned 32 ÷ 2 transitive
      do: find a replacement (none named); it comes in through stof/doctrine-extensions-bundle
          (gedmo/doctrine-extensions requires it): replace or update stof/doctrine-extensions-bundle
      abandoned    marked abandoned by its registry; its repository is archived on GitHub; last release 2022-03-30 (4.6
                   years ago)
  doctrine/annotations 2.0.2  via sensio/framework-extra-bundle  ·  abandoned
      16 = abandoned 32 ÷ 2 transitive
      do: find a replacement (none named); it comes in through sensio/framework-extra-bundle: replace
          sensio/framework-extra-bundle; the newest release of sensio/framework-extra-bundle (v6.2.10) still requires it
      abandoned    marked abandoned by its registry
      age: installed 2.0.2 released 2024-09-05 (2.1 years ago), its newest release; last push 2025-12-11 (0.9 years
           ago)
  doctrine/cache 2.2.0  via craue/config-bundle, doctrine/doctrine-bundle, doctrine/doctrine-migrations-bundle and 3 more  ·  abandoned
      16 = abandoned 32 ÷ 2 transitive
      do: find a replacement (none named); it comes in through craue/config-bundle, doctrine/doctrine-bundle,
          doctrine/doctrine-migrations-bundle and 3 more: replace or update craue/config-bundle,
          doctrine/doctrine-bundle, doctrine/doctrine-migrations-bundle and 3 more; the newest releases of
          doctrine/doctrine-bundle (3.3.3) and doctrine/orm (3.7.5) no longer require it
      abandoned    marked abandoned by its registry; last release 2022-05-20 (4.4 years ago)
  hoa/compiler 3.17.08.08  via wallabag/rulerz, wallabag/rulerz-bundle  ·  abandoned
      16 = abandoned 32 ÷ 2 transitive
      do: find a replacement (none named); it comes in through wallabag/rulerz (hoa/ruler requires it),
          wallabag/rulerz-bundle: replace or update wallabag/rulerz, wallabag/rulerz-bundle
      abandoned    marked abandoned by its registry; its repository is archived on GitHub; last release 2017-08-08 (9.2
                   years ago); last push 2021-04-29 (5.5 years ago)
  hoa/event 1.17.01.13  via wallabag/rulerz, wallabag/rulerz-bundle  ·  abandoned
      16 = abandoned 32 ÷ 2 transitive
      do: find a replacement (none named); it comes in through wallabag/rulerz (hoa/exception requires it),
          wallabag/rulerz-bundle: replace or update wallabag/rulerz, wallabag/rulerz-bundle
      abandoned    marked abandoned by its registry; its repository is archived on GitHub; last release 2017-08-30 (9.2
                   years ago); last push 2021-04-29 (5.5 years ago)
  hoa/exception 1.17.01.16  via wallabag/rulerz, wallabag/rulerz-bundle  ·  abandoned
      16 = abandoned 32 ÷ 2 transitive
      do: find a replacement (none named); it comes in through wallabag/rulerz (hoa/consistency requires it),
          wallabag/rulerz-bundle: replace or update wallabag/rulerz, wallabag/rulerz-bundle
      abandoned    marked abandoned by its registry; its repository is archived on GitHub; last release 2017-08-30 (9.2
                   years ago); last push 2021-04-29 (5.5 years ago)
  hoa/file 1.17.07.11  via wallabag/rulerz, wallabag/rulerz-bundle  ·  abandoned
      16 = abandoned 32 ÷ 2 transitive
      do: find a replacement (none named); it comes in through wallabag/rulerz (hoa/ruler requires it),
          wallabag/rulerz-bundle: replace or update wallabag/rulerz, wallabag/rulerz-bundle
      abandoned    marked abandoned by its registry; its repository is archived on GitHub; last release 2017-07-11 (9.3
                   years ago); last push 2018-01-23 (8.8 years ago)
  hoa/iterator 2.17.01.10  via wallabag/rulerz, wallabag/rulerz-bundle  ·  abandoned
      16 = abandoned 32 ÷ 2 transitive
      do: find a replacement (none named); it comes in through wallabag/rulerz (hoa/compiler requires it),
          wallabag/rulerz-bundle: replace or update wallabag/rulerz, wallabag/rulerz-bundle
      abandoned    marked abandoned by its registry; its repository is archived on GitHub; last release 2017-01-10 (9.8
                   years ago); last push 2021-04-29 (5.5 years ago)
  hoa/math 1.17.05.16  via wallabag/rulerz, wallabag/rulerz-bundle  ·  abandoned
      16 = abandoned 32 ÷ 2 transitive
      do: find a replacement (none named); it comes in through wallabag/rulerz (hoa/compiler requires it),
          wallabag/rulerz-bundle: replace or update wallabag/rulerz, wallabag/rulerz-bundle
      abandoned    marked abandoned by its registry; its repository is archived on GitHub; last release 2017-05-16 (9.4
                   years ago); last push 2021-04-29 (5.5 years ago)
  hoa/protocol 1.17.01.14  via wallabag/rulerz, wallabag/rulerz-bundle  ·  abandoned
      16 = abandoned 32 ÷ 2 transitive
      do: find a replacement (none named); it comes in through wallabag/rulerz (hoa/ruler requires it),
          wallabag/rulerz-bundle: replace or update wallabag/rulerz, wallabag/rulerz-bundle
      abandoned    marked abandoned by its registry; its repository is archived on GitHub; last release 2017-01-14 (9.8
                   years ago); last push 2021-04-29 (5.5 years ago)
  hoa/regex 1.17.01.13  via wallabag/rulerz, wallabag/rulerz-bundle  ·  abandoned
      16 = abandoned 32 ÷ 2 transitive
      do: find a replacement (none named); it comes in through wallabag/rulerz (hoa/compiler requires it),
          wallabag/rulerz-bundle: replace or update wallabag/rulerz, wallabag/rulerz-bundle
      abandoned    marked abandoned by its registry; its repository is archived on GitHub; last release 2017-01-13 (9.8
                   years ago); last push 2018-07-20 (8.3 years ago)
  hoa/ruler 2.17.05.16  via wallabag/rulerz, wallabag/rulerz-bundle  ·  abandoned
      16 = abandoned 32 ÷ 2 transitive
      do: find a replacement (none named); it comes in through wallabag/rulerz, wallabag/rulerz-bundle: replace or
          update wallabag/rulerz, wallabag/rulerz-bundle
      abandoned    marked abandoned by its registry; its repository is archived on GitHub; last release 2017-05-16 (9.4
                   years ago); last push 2021-07-10 (5.3 years ago)
  hoa/stream 1.17.02.21  via wallabag/rulerz, wallabag/rulerz-bundle  ·  abandoned
      16 = abandoned 32 ÷ 2 transitive
      do: find a replacement (none named); it comes in through wallabag/rulerz (hoa/file requires it),
          wallabag/rulerz-bundle: replace or update wallabag/rulerz, wallabag/rulerz-bundle
      abandoned    marked abandoned by its registry; its repository is archived on GitHub; last release 2017-02-21 (9.7
                   years ago); last push 2021-08-09 (5.2 years ago)
  hoa/ustring 4.17.01.16  via wallabag/rulerz, wallabag/rulerz-bundle  ·  abandoned
      16 = abandoned 32 ÷ 2 transitive
      do: find a replacement (none named); it comes in through wallabag/rulerz (hoa/regex requires it),
          wallabag/rulerz-bundle: replace or update wallabag/rulerz, wallabag/rulerz-bundle
      abandoned    marked abandoned by its registry; its repository is archived on GitHub; last release 2017-01-16 (9.8
                   years ago); last push 2021-04-29 (5.5 years ago)
  hoa/visitor 2.17.01.16  via wallabag/rulerz, wallabag/rulerz-bundle  ·  abandoned
      16 = abandoned 32 ÷ 2 transitive
      do: find a replacement (none named); it comes in through wallabag/rulerz (hoa/ruler requires it),
          wallabag/rulerz-bundle: replace or update wallabag/rulerz, wallabag/rulerz-bundle
      abandoned    marked abandoned by its registry; its repository is archived on GitHub; last release 2017-01-16 (9.8
                   years ago); last push 2021-04-28 (5.5 years ago)
  hoa/zformat 1.17.01.10  via wallabag/rulerz, wallabag/rulerz-bundle  ·  abandoned
      16 = abandoned 32 ÷ 2 transitive
      do: find a replacement (none named); it comes in through wallabag/rulerz (hoa/math requires it),
          wallabag/rulerz-bundle: replace or update wallabag/rulerz, wallabag/rulerz-bundle
      abandoned    marked abandoned by its registry; its repository is archived on GitHub; last release 2017-01-10 (9.8
                   years ago); last push 2018-02-12 (8.7 years ago)
  symfony/security-guard v5.4.45  via friendsofsymfony/oauth-server-bundle, friendsofsymfony/user-bundle, scheb/2fa-backup-code and 5 more  ·  abandoned
      16 = abandoned 32 ÷ 2 transitive
      do: find a replacement (none named); it comes in through friendsofsymfony/oauth-server-bundle,
          friendsofsymfony/user-bundle, scheb/2fa-backup-code and 5 more: replace or update
          friendsofsymfony/oauth-server-bundle, friendsofsymfony/user-bundle, scheb/2fa-backup-code and 5 more; the
          newest release of symfony/security-bundle (v8.1.9) no longer requires it
      abandoned    marked abandoned by its registry; its repository is archived on GitHub
      age: installed v5.4.45 released 2024-09-25 (2.1 years ago), its newest release; last push 2024-10-27 (2.0
           years ago)

medium (12)
  enshrined/svg-sanitize 0.22.0  direct  ·  vulnerable
      8 = vulnerable 8 [medium advisory]
      do: require enshrined/svg-sanitize ^1.0.0 — clears 3 advisories; 1.x needs php ^7.1 || ^8.0, which require.php
          allows; the target php 8.4 runs it; held by composer.json (requires ^0.22); a new major brings its own
          requirements; Composer's solver checked it against the lock
      vulnerable   3 advisories: 3 medium — none fixed on 0.22.x; worst: CVE-2026-107381 svg-sanitizer: Mixed-case
                   xlink:HrEf skips the `<use>` nesting-DoS check in Resolver::processReferences
      age: installed 0.22.0 released 2025-08-12 (1.2 years ago), the newest on 0.22.x; newest release 1.1.1
           2026-10-20 (less than three weeks ago); last push 2026-10-20 (less than three weeks ago)
  symfony/expression-language v5.4.45  direct  ·  vulnerable
      8 = vulnerable 8 [unrated advisory]
      do: composer update 'symfony/expression-language:^5.4.54'
          (gets v5.4.54) — clears the advisory; Composer's solver checked it against the lock
      vulnerable   1 advisory: 1 unrated — all fixed on 5.x; advisory: CVE-2026-109449 Unbounded Nesting in the
                   Expression Parser Lets Untrusted Expressions Crash the Process
      age: installed v5.4.45 released 2024-10-04 (2.1 years ago); 5.x last released v5.4.54 2026-10-09 (less than
           three weeks ago); newest release v8.1.9 2026-10-09 (less than three weeks ago); last push 2026-10-15 (less
           than three weeks ago)
  symfony/http-foundation v5.4.50  direct  ·  vulnerable
      8 = vulnerable 8 [unrated advisory]
      do: composer update 'symfony/http-foundation:^5.4.54'
          (gets v5.4.54) — clears the advisory; Composer's solver checked it against the lock
      vulnerable   1 advisory: 1 unrated — all fixed on 5.x; advisory: CVE-2026-108082 ReDoS in HeaderUtils::split()
                   Reachable Through the Cache-Control Request Header
      age: installed v5.4.50 released 2025-11-03 (1.0 years ago); 5.x last released v5.4.54 2026-09-30 (0.1 years
           ago); newest release v8.1.9 2026-10-08 (less than three weeks ago); last push 2026-10-25 (less than three
           weeks ago)
  phpseclib/phpseclib 3.0.55  via php-amqplib/php-amqplib, php-amqplib/rabbitmq-bundle  ·  vulnerable
      8 = vulnerable 8 [medium advisory]
      do: composer update 'phpseclib/phpseclib:^3.0.57'
          (gets 3.0.57) — clears the advisory; Composer's solver checked it against the lock
      vulnerable   1 advisory: 1 medium — all fixed on 3.x; advisory: CVE-2026-84308 phpseclib — non-constant-time
                   X25519 scalar multiplication permits full private-key recovery
      age: installed 3.0.55 released 2026-06-14 (0.4 years ago); 3.x last released 3.0.57 2026-08-26 (0.2 years
           ago); newest release 4.0.2 2026-10-07 (0.1 years ago); last push 2026-10-26 (less than three weeks ago)
  symfony/yaml v6.4.43  via nelmio/api-doc-bundle, php-amqplib/rabbitmq-bundle, sentry/sentry-symfony  ·  vulnerable
      8 = vulnerable 8 [unrated advisory]
      do: composer update 'symfony/yaml:^6.4.45'
          (gets v6.4.48) — clears the advisory; Composer's solver checked it against the lock
      vulnerable   1 advisory: 1 unrated — all fixed on 6.x; advisory: CVE-2026-109413 Unbounded Recursion in the YAML
                   Inline Lexer Lets a Single Document Crash the Process
      age: installed v6.4.43 released 2026-07-20 (0.3 years ago); 6.x last released v6.4.48 2026-10-12 (less than
           three weeks ago); newest release v8.1.9 2026-10-12 (less than three weeks ago); last push 2026-10-24 (less
           than three weeks ago)
  defuse/php-encryption v2.4.0  direct  ·  stale
      8 = stale 8
      do: —
      stale        last release 2023-06-19 (3.4 years ago)
  pragmarx/recovery v0.2.1  direct  ·  stale
      8 = stale 8
      do: —
      stale        last release 2021-08-15 (5.2 years ago)
      pulls in 1 flagged package: pragmarx/random (silent · old-promise)
  wallabag/phpepub 4.0.10  direct  ·  stale
      8 = stale 8
      do: —
      stale        last release 2022-03-21 (4.6 years ago)
      pulls in 5 flagged packages: grandt/binstring (silent · old-promise), grandt/phpresizegif (silent ·
      old-promise), grandt/phpzipmerge (silent · old-promise), grandt/relativepath (silent · old-promise),
      phpzip/phpzip (silent · old-promise)
  smalot/pdfparser v1.1.0  via j0k3r/graby  ·  left-behind
      8 = left-behind 16 ÷ 2 transitive
      do: move smalot/pdfparser to ^2.13 through j0k3r/graby — clears left-behind; 2.x needs php >=7.1, which
          require.php allows; the target php 8.4 runs it; held by j0k3r/graby 2.5.2 (requires ^1.1); the packages that
          hold it may need to move too; a new major brings its own requirements; Composer's solver refuses it against
          the lock
      left-behind  branch 1.x last released 2021-08-03 (5.2 years ago); 2.x released v2.13.1 (2026-10-13)
  symfony/psr-http-message-bridge v2.3.1  via sentry/sentry-symfony  ·  left-behind
      8 = left-behind 16 ÷ 2 transitive
      do: move symfony/psr-http-message-bridge to ^7.4 through sentry/sentry-symfony — clears left-behind; 7.x needs
          php >=8.2, which require.php allows; the target php 8.4 runs it; held by composer.json (requires
          symfony/http-foundation ^5.4.48) and symfony/psr-http-message-bridge v7.4.0, its own release (requires
          symfony/http-foundation ^6.4|^7.0|^8.0) and symfony/psr-http-message-bridge v7.4.0, its own release (conflicts
          with symfony/http-kernel <6.4) and sentry/sentry-symfony 5.2.0 (requires ^1.2||^2.0||^6.4||^7.0) and
          sentry/sentry-symfony 5.12.0 (requires ^1.2||^2.0||^6.4||^7.0||^8.0) and sentry/sentry-symfony 5.13.0
          (requires sentry/sentry ^4.31.0) and sentry/sentry-symfony 5.14.0 (requires sentry/sentry ^4.34.0); the
          packages that hold it may need to move too; a new major brings its own requirements; Composer's solver refuses
          it against the lock
      left-behind  branch 2.x last released 2023-07-26 (3.3 years ago); 8.x released v8.1.9 (2026-10-07), needs php
                   >=8.4.1 above the project's php >=8.2; 7.x released v7.4.21 (2026-10-07)
  thecodingmachine/safe v2.5.0  via scheb/2fa-google-authenticator  ·  left-behind
      8 = left-behind 16 ÷ 2 transitive
      do: move thecodingmachine/safe to ^3.4 through scheb/2fa-google-authenticator — clears left-behind; 3.x needs
          php ^8.1, which require.php allows; the target php 8.4 runs it; held by composer.json (requires
          scheb/2fa-bundle ^5.13.2) and spomky-labs/otphp v10.0.3 (requires ^0.1.14|^1.0|^2.0; itself left-behind) and
          scheb/2fa-google-authenticator v5.13.2 (requires spomky-labs/otphp ^9.1|^10.0; itself left-behind) and
          scheb/2fa-google-authenticator 5.x-dev (requires scheb/2fa-bundle self.version (5.x-dev)); the packages that
          hold it may need to move too; a new major brings its own requirements; Composer's solver refuses it against
          the lock
      left-behind  branch 2.x last released 2023-04-05 (3.6 years ago); 3.x released v3.4.0 (2026-02-04)
  wallabag/rulerz-bridge dev-master  via wallabag/rulerz-bundle  ·  pinned
      8 = pinned 16 ÷ 2 transitive
      do: no tagged release to move to; review the snapshot, or replace it
      pinned       pinned to branch snapshot dev-master
      age: last push 2023-12-24 (2.8 years ago)

low (4)
  friendsofsymfony/oauth2-php 1.3.1  via friendsofsymfony/oauth-server-bundle  ·  stale
      4 = stale 8 ÷ 2 transitive
      do: —
      stale        last release 2021-04-06 (5.6 years ago)
  lcobucci/clock 3.3.1  via lcobucci/jwt, scheb/2fa-trusted-device  ·  capped
      4 = capped 8 ÷ 2 transitive
      do: raise require.php to >=8.4, then move lcobucci/clock to ^3.6.0 through lcobucci/jwt, scheb/2fa-trusted-device
          — clears capped; 3.x needs php ~8.4.0 || ~8.5.0, require.php allows 8.2 (a minor raise); the target php 8.4
          runs it; what requires it must allow it; the newest release of lcobucci/jwt (5.6.1) no longer requires it;
          Composer's solver checked it against the lock
      capped       requires php ~8.2.0 || ~8.3.0 || ~8.4.0: it stops at 8.4, so the newest PHP, 8.5, is excluded
      age: installed 3.3.1 released 2024-09-24 (2.1 years ago); 3.x last released 3.6.0 2026-04-13 (0.5 years ago),
           its newest release; last push 2026-10-24 (less than three weeks ago)
  willdurand/jsonp-callback-validator v2.0.0  via friendsofsymfony/jsrouting-bundle, friendsofsymfony/rest-bundle  ·  stale
      4 = stale 8 ÷ 2 transitive
      do: —
      stale        last release 2022-01-30 (4.7 years ago); last push 2023-07-29 (3.2 years ago)
  willdurand/negotiation 3.1.0  via friendsofsymfony/rest-bundle  ·  stale
      4 = stale 8 ÷ 2 transitive
      do: —
      stale        last release 2022-01-30 (4.7 years ago); last push 2023-08-03 (3.2 years ago)

200 packages checked · 58 flagged: critical 3 · high 39 · medium 12 · low 4
flags: abandoned 19 · silent 8 · pinned 4 · left-behind 13 · old-promise 10 · stale 8 · capped 1 ·
vulnerable 7 · 12 packages carry more than one
depth: 36 of 58 flagged packages are named by no line of composer.json · direct 22 · 1 hop 20 · 2 hops 9 ·
3 hops 5 · 4 hops 2 ·
deepest: hoa/ustring and hoa/zformat, 4 hops (wallabag/rulerz › hoa/ruler › hoa/compiler › hoa/regex › hoa/ustring)
security: 7 vulnerable packages · 10 advisories: 1 high, 5 medium, 4 unrated · packages-dev not checked (pass --dev)
fix, by package: composer update 4 · upgrade 2 · no fix 1
update now: composer update 'symfony/expression-language:^5.4.54' 'symfony/http-foundation:^5.4.54' 'phpseclib/phpseclib:^3.0.57' 'symfony/yaml:^6.4.45'
libyears: at least 189.8 behind across 194 of 200 packages · 120.2 from direct requirements ·
furthest behind phpdocumentor/reflection-common 2.2.0 at 5.4
pulled in by: wallabag/rulerz-bundle 15 · wallabag/rulerz 14 · wallabag/phpepub 5 ·
scheb/2fa-google-authenticator 3 · friendsofsymfony/oauth-server-bundle 2 · … and 23 more
advisories: 200 packages asked · packagist.org answered (184 on record for 50 packages)
moves: checked with the solver of Composer 2.10.3 against the lock; ext-*, lib-*, php-debug, php-ipv6 and php-zts requirements not checked
php: the lock installs on PHP 8.2 to 8.4; lcobucci/clock excludes 8.5
gate: off · --fail-on=none (default) · target php 8.4 (--target-php)
Data as of 2026-10-26 (package repositories, repository hosts) · score model 1. Run composer lockrot --format=json for
details.
```

## Reading the table {#the-table-format}

- **Groups.** One per grade, highest first, with its count. `--all` adds the findings that score 0.

- **A finding's first line.** The package and its installed version, then `direct`, or `via` and
  the direct requirements that reach it (`?` when nothing in the project reaches it). After the
  `·` come the counted [flags](verdicts.md#flags), in flag order.

- **The score line.** The [score](verdicts.md#score) as a sum of its terms, with the rule that
  changed each one: `[¼ of 16]` for a flag after the lead, `÷ 2 transitive` for a package that
  `composer.json` does not require directly.

- **The `do:` line.** The [move](verdicts.md#move): what to do, what it clears, what holds it and
  what Composer's solver says about it ([the move check](verdicts.md#the-move-check)).
  `do: —` when `stale` leads: the package is quiet, and lockrot names no move for it.

- **The evidence.** One line per counted flag, with its dates. `pulls in N flagged packages:` on a
  direct requirement is [S7](verdicts.md#transitive-exposure).

- **The summary.** In order, each line only when it applies:

    - the count of findings per grade;
    - `flags:`, the findings per flag, and how many carry more than one;
    - `depth:` and `deepest:`, how far below `composer.json` the graded packages sit
      ([rot depth](verdicts.md#rot-depth));
    - `security:`, `fix, by package:` and `update now:`, the advisories and their fixes
      ([security advisories](verdicts.md#security-advisories));
    - [`libyears:`](verdicts.md#libyears), on every run;
    - `pulled in by:`, the S7 totals per direct requirement;
    - `advisories:`, what the advisory lookup asked and what each repository answered;
    - `moves:`, which Composer's solver checked the moves ([the move check](verdicts.md#the-move-check));
    - `php:`, the PHP minors on which the lock installs, and the packages that exclude the next minor
      ([the PHP window](verdicts.md#the-php-window));
    - `gate:`, the [gate line](ci.md#reading-the-gate-line);
    - with a baseline, its summary line ([baseline.md](baseline.md)).

- **The footer.** `Data as of` is the date the report was generated, in UTC, followed by the
  sources and the score model. The clause changes when an answer came from lockrot's cache
  ([how fresh the data is](internals.md#two-sources-two-clocks)). Run notes follow as `note:` lines
  ([run notes](notes.md)), and with a baseline a `note:` line names its stale entries.

- **Width.** Lines wrap to `COLUMNS` when it is a positive integer. With `COLUMNS` unset, they wrap
  to the width Composer's console reports (with no terminal: 80, or none under Composer 2.2 LTS),
  else to 120. Never below 40. A command or path in the summary stays on one line, so it can be
  wider. For a `table` report written to a file, see
  [Writing reports to files](configuration.md#writing-reports-to-files).

- **Colour.** On a terminal, a finding's grade is red in the `critical` and `high` groups and
  yellow in `medium`.

## The same lock as JSON

`--format=json` writes every field of the report. The `html` page carries it as its embedded data's
`report` key ([`--format=html`](ci.md#-formathtml)). This is the same run's document, abridged to
one finding, spomky-labs/otphp. `…` marks each cut: the other findings, the rest of `exposure` and
`rot_depth.by_depth`, and the rest of each list in `run.score_model`.

```json
{
    "$schema": "https://lockrot.dev/schema/report-2.json",
    "lockrot": {
        "version": "0.14.0",
        "schema": 2
    },
    "generated_at": "2026-10-26T08:46:29+00:00",
    "run": {
        "project": "wallabag/wallabag",
        "root_package": "wallabag/wallabag",
        "target_php": "8.4",
        "target_php_source": "option",
        "project_php": ">=8.2",
        "project_php_lowest": "8.2.0",
        "lock_file": "composer.lock",
        "fail_on": "none",
        "fail_on_source": "default",
        "gates": [],
        "strict_network": false,
        "mode": "check",
        "include_dev": false,
        "thresholds": {
            "release-warn-years": 3,
            "release-high-years": 5,
            "push-warn-years": 3,
            "push-high-years": 5
        },
        "flag_ids": [
            "abandoned",
            "silent",
            "pinned",
            "left-behind",
            "old-promise",
            "stale",
            "capped",
            "vulnerable"
        ],
        "verdicts": [
            "critical",
            "high",
            "medium",
            "low",
            "unknown",
            "finished",
            "ok"
        ],
        "graded_verdicts": [
            "critical",
            "high",
            "medium",
            "low"
        ],
        "signal_ids": [
            "S1",
            "S2",
            "S3",
            "S4",
            "S5",
            "S6",
            "S7",
            "S8",
            "S9",
            "S10",
            "S11"
        ],
        "fix_model": 1,
        "text_grammar": 1,
        "score_model": {
            "id": 1,
            "score_text_grammar": 1,
            "flag_order": [
                "abandoned",
                "silent",
                "pinned",
                "left-behind",
                "old-promise",
                "stale",
                "capped",
                "vulnerable"
            ],
            "parts": [
                {
                    "id": "maintenance",
                    "flag_ids": [
                        "abandoned",
                        "silent",
                        "pinned",
                        "left-behind",
                        "old-promise",
                        "stale",
                        "capped"
                    ],
                    "combine": "lead_plus_share"
                },
                {
                    "id": "security",
                    "flag_ids": [
                        "vulnerable"
                    ],
                    "combine": "max"
                }
            ],
            "flags": [
                {
                    "id": "abandoned",
                    "part": "maintenance",
                    "points": 32,
                    "band": "critical",
                    "can_corroborate": false,
                    "corroborating_points": null,
                    "max_maintenance": 40,
                    "max_maintenance_flags": [
                        "abandoned",
                        "left-behind",
                        "old-promise"
                    ],
                    "raised_by": [
                        "S1",
                        "S3"
                    ],
                    "thresholds": [],
                    "basis": {
                        "v013_base": "critical",
                        "points": "band_floor"
                    },
                    "points_from": null
                },
                {
                    "id": "silent",
                    "part": "maintenance",
                    "points": 32,
                    "band": "critical",
                    "can_corroborate": false,
                    "corroborating_points": null,
                    "max_maintenance": 40,
                    "max_maintenance_flags": [
                        "silent",
                        "pinned",
                        "old-promise"
                    ],
                    "raised_by": [
                        "S2",
                        "S4"
                    ],
                    "thresholds": [
                        "release-high-years",
                        "push-high-years"
                    ],
                    "basis": {
                        "v013_base": "critical",
                        "points": "band_floor"
                    },
                    "points_from": null
                },
                …
            ],
            "severities": [
                {
                    "id": "critical",
                    "points": 32,
                    "band": "critical",
                    "points_no_reachable_fix": 64,
                    "band_no_reachable_fix": "critical",
                    "gate_rank": 4,
                    "counts_as": null,
                    "basis": {
                        "points": "band_floor"
                    }
                },
                {
                    "id": "high",
                    "points": 16,
                    "band": "high",
                    "points_no_reachable_fix": 32,
                    "band_no_reachable_fix": "critical",
                    "gate_rank": 3,
                    "counts_as": null,
                    "basis": {
                        "points": "band_floor"
                    }
                },
                …
            ],
            "fix_kinds": [
                {
                    "id": "update",
                    "ease": 1,
                    "doubles": false
                },
                {
                    "id": "upgrade",
                    "ease": 2,
                    "doubles": false
                },
                …
            ],
            "bands": [
                {
                    "verdict": "critical",
                    "floor": 32
                },
                {
                    "verdict": "high",
                    "floor": 16
                },
                {
                    "verdict": "medium",
                    "floor": 8
                },
                {
                    "verdict": "low",
                    "floor": 1
                }
            ],
            "zero_verdicts": [
                {
                    "verdict": "finished",
                    "when": "allowlisted"
                },
                {
                    "verdict": "unknown",
                    "when": "no_metadata"
                },
                {
                    "verdict": "ok",
                    "when": "otherwise"
                }
            ],
            "exact_unit": 0.5,
            "rounding": "floor_once",
            "max_total": 104,
            "bar_max": 64,
            "bar_overflow": "clip",
            "exclusive_groups": [
                {
                    "id": "liveness",
                    "flag_ids": [
                        "abandoned",
                        "silent",
                        "stale"
                    ],
                    "holds_unless": null
                },
                …
            ],
            "sort": [
                {
                    "key": "verdict",
                    "path": "verdict",
                    "dir": "order",
                    "order": [
                        [
                            "critical"
                        ],
                        [
                            "high"
                        ],
                        [
                            "medium"
                        ],
                        [
                            "low"
                        ],
                        [
                            "unknown"
                        ],
                        [
                            "finished",
                            "ok"
                        ]
                    ],
                    "default": null,
                    "collation": null
                },
                {
                    "key": "security",
                    "path": "score.parts.security.contribution",
                    "dir": "desc",
                    "default": 0,
                    "collation": null
                },
                …
            ],
            "rules": [
                {
                    "id": "counted",
                    "stage": 1,
                    "kind": "select",
                    "applies_to": "flags",
                    "accept_group": "liveness",
                    "accept_covers": "listed_and_below",
                    "why": "accepted-facts-shown-not-counted",
                    "doc": "score-counted",
                    "illustration": null
                },
                {
                    "id": "lead-first",
                    "stage": 2,
                    "kind": "select",
                    "applies_to": "maintenance",
                    "order": "flag_order",
                    "share": {
                        "num": 1,
                        "den": 1
                    },
                    "why": "strongest-fact-in-full",
                    "doc": "score-lead-first",
                    "illustration": null
                },
                …
            ],
            "docs": "https://lockrot.dev/verdicts/model-1/"
        },
        "advisory_lookup": {
            "scope": "all",
            "scope_source": "default",
            "packages_asked": 200,
            "packages_not_asked": 0,
            "other_repositories": 0,
            "repositories": [
                {
                    "composer_repository": "composer repo (https://repo.packagist.org)",
                    "registry": "packagist.org",
                    "outcome": "answered",
                    "reason": null,
                    "message": null,
                    "packages_with_records": 50,
                    "records": 184
                }
            ]
        },
        "move_check": {
            "composer": "2.10.3",
            "blocks_advisories": true,
            "blocking_disabled_by": [],
            "ignored_platform": [
                "ext-*",
                "lib-*",
                "php-debug",
                "php-ipv6",
                "php-zts"
            ],
            "baseline": "resolves",
            "reason": null
        },
        "php_window": {
            "platform_override": null,
            "host_requires": [],
            "with_dev": {
                "minors": [
                    "8.2",
                    "8.3",
                    "8.4"
                ],
                "gaps": false,
                "lower_edge": {
                    "php": "8.2.0",
                    "set_by": [
                        {
                            "name": null,
                            "version": null,
                            "link": "require",
                            "target": "php",
                            "constraint": ">=8.2",
                            "dev": false
                        },
                        {
                            "name": "laminas/laminas-code",
                            "version": "4.17.0",
                            "link": "require",
                            "target": "php",
                            "constraint": "~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0",
                            "dev": false
                        },
                        {
                            "name": "lcobucci/clock",
                            "version": "3.3.1",
                            "link": "require",
                            "target": "php",
                            "constraint": "~8.2.0 || ~8.3.0 || ~8.4.0",
                            "dev": false
                        },
                        {
                            "name": "symfony/stopwatch",
                            "version": "v7.4.8",
                            "link": "require",
                            "target": "php",
                            "constraint": ">=8.2",
                            "dev": false
                        },
                        {
                            "name": "symfony/var-exporter",
                            "version": "v7.4.9",
                            "link": "require",
                            "target": "php",
                            "constraint": ">=8.2",
                            "dev": false
                        }
                    ]
                },
                "upper_edge": {
                    "excludes": "8.5",
                    "set_by": [
                        {
                            "name": "lcobucci/clock",
                            "version": "3.3.1",
                            "link": "require",
                            "target": "php",
                            "constraint": "~8.2.0 || ~8.3.0 || ~8.4.0",
                            "dev": false
                        }
                    ]
                },
                "closest": null
            },
            "without_dev": {
                "minors": [
                    "8.2",
                    "8.3",
                    "8.4"
                ],
                "gaps": false,
                "lower_edge": {
                    "php": "8.2.0",
                    "set_by": [
                        {
                            "name": null,
                            "version": null,
                            "link": "require",
                            "target": "php",
                            "constraint": ">=8.2",
                            "dev": false
                        },
                        {
                            "name": "laminas/laminas-code",
                            "version": "4.17.0",
                            "link": "require",
                            "target": "php",
                            "constraint": "~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0",
                            "dev": false
                        },
                        {
                            "name": "lcobucci/clock",
                            "version": "3.3.1",
                            "link": "require",
                            "target": "php",
                            "constraint": "~8.2.0 || ~8.3.0 || ~8.4.0",
                            "dev": false
                        },
                        {
                            "name": "symfony/stopwatch",
                            "version": "v7.4.8",
                            "link": "require",
                            "target": "php",
                            "constraint": ">=8.2",
                            "dev": false
                        },
                        {
                            "name": "symfony/var-exporter",
                            "version": "v7.4.9",
                            "link": "require",
                            "target": "php",
                            "constraint": ">=8.2",
                            "dev": false
                        }
                    ]
                },
                "upper_edge": {
                    "excludes": "8.5",
                    "set_by": [
                        {
                            "name": "lcobucci/clock",
                            "version": "3.3.1",
                            "link": "require",
                            "target": "php",
                            "constraint": "~8.2.0 || ~8.3.0 || ~8.4.0",
                            "dev": false
                        }
                    ]
                },
                "closest": null
            },
            "target": {
                "php": "8.4.99",
                "inside": true
            },
            "target_fix": null
        },
        "score_rules_used": {
            "counted": 10,
            "lead-first": 52,
            "corroborating-share": 11,
            "advisory-points": 7,
            "no-reachable-fix-multiplier": 1,
            "security-max": 2,
            "divide-reach": 40,
            "security-exempt-from-reach": 4,
            "sum": 1,
            "divide-dev": 0,
            "floor-once": 0,
            "band-floors": 58,
            "zero-verdicts": 142,
            "sort": null,
            "gate-bounded-new": 0,
            "baseline-cover": 0
        }
    },
    "activity_cache_oldest_at": null,
    "activity_cache_age_hours": null,
    "packages_checked": 200,
    "packages_flagged": 58,
    "packages_multi_flag": 12,
    "include_dev": false,
    "not_from_composer_repository": 0,
    "packages_unchecked_accepted": 0,
    "network_failures": false,
    "counts": {
        "critical": 3,
        "high": 39,
        "medium": 12,
        "low": 4,
        "unknown": 0,
        "finished": 18,
        "ok": 124
    },
    "abandoned": {
        "total": 19,
        "with_replacement": 0,
        "with_suggestion": 1
    },
    "priorities": {
        "critical": 3,
        "high": 39,
        "medium": 12,
        "low": 4,
        "none": 142
    },
    "flags": {
        "abandoned": {
            "carrying": 19,
            "leading": 19,
            "accepted": {
                "all": 0,
                "in_graded": 0
            },
            "by_verdict": {
                "critical": 1,
                "high": 18,
                "medium": 0,
                "low": 0
            }
        },
        "silent": {
            "carrying": 8,
            "leading": 8,
            "accepted": {
                "all": 0,
                "in_graded": 0
            },
            "by_verdict": {
                "critical": 2,
                "high": 6,
                "medium": 0,
                "low": 0
            }
        },
        "pinned": {
            "carrying": 4,
            "leading": 4,
            "accepted": {
                "all": 0,
                "in_graded": 0
            },
            "by_verdict": {
                "critical": 0,
                "high": 3,
                "medium": 1,
                "low": 0
            }
        },
        "left-behind": {
            "carrying": 13,
            "leading": 13,
            "accepted": {
                "all": 1,
                "in_graded": 0
            },
            "by_verdict": {
                "critical": 0,
                "high": 10,
                "medium": 3,
                "low": 0
            }
        },
        "old-promise": {
            "carrying": 10,
            "leading": 1,
            "accepted": {
                "all": 3,
                "in_graded": 0
            },
            "by_verdict": {
                "critical": 2,
                "high": 8,
                "medium": 0,
                "low": 0
            }
        },
        "stale": {
            "carrying": 8,
            "leading": 6,
            "accepted": {
                "all": 9,
                "in_graded": 0
            },
            "by_verdict": {
                "critical": 0,
                "high": 2,
                "medium": 3,
                "low": 3
            }
        },
        "capped": {
            "carrying": 1,
            "leading": 1,
            "accepted": {
                "all": 0,
                "in_graded": 0
            },
            "by_verdict": {
                "critical": 0,
                "high": 0,
                "medium": 0,
                "low": 1
            }
        },
        "vulnerable": {
            "carrying": 7,
            "leading": null,
            "accepted": null,
            "by_verdict": {
                "critical": 0,
                "high": 2,
                "medium": 5,
                "low": 0
            }
        }
    },
    "exposure": [
        {
            "package": "wallabag/rulerz-bundle",
            "flagged": 15
        },
        {
            "package": "wallabag/rulerz",
            "flagged": 14
        },
        {
            "package": "wallabag/phpepub",
            "flagged": 5
        },
        …
    ],
    "exposure_rule": {
        "max_fan_in": 8
    },
    "unattributed": [],
    "rot_depth": {
        "flagged": 58,
        "measured": 58,
        "unmeasured": {
            "unreached": 0,
            "no_composer_json": 0
        },
        "by_reach": {
            "direct": 22,
            "transitive": 36,
            "unreached": 0
        },
        "not_in_composer_json": 36,
        "require_dev_not_walked": 0,
        "production": 58,
        "development": 0,
        "by_depth": [
            {
                "depth": 0,
                "flagged": 22
            },
            {
                "depth": 1,
                "flagged": 20
            },
            {
                "depth": 2,
                "flagged": 9
            },
            …
        ],
        "max_depth": 4,
        "deepest": [
            {
                "package": "hoa/ustring",
                "chain": [
                    "wallabag/rulerz",
                    "hoa/ruler",
                    "hoa/compiler",
                    "hoa/regex",
                    "hoa/ustring"
                ]
            },
            {
                "package": "hoa/zformat",
                "chain": [
                    "wallabag/rulerz",
                    "hoa/ruler",
                    "hoa/compiler",
                    "hoa/math",
                    "hoa/zformat"
                ]
            }
        ]
    },
    "libyears": {
        "total": 189.74,
        "direct_requirements": 120.24,
        "measured": 194,
        "at_least_count": 3,
        "unmeasured": {
            "not_from_composer_repository": 0,
            "unavailable": 0,
            "not_found": 0,
            "branch_snapshot": 4,
            "shared_commit": 2,
            "undated": 0,
            "no_stable_release": 0,
            "undated_releases": 0
        },
        "furthest_behind": {
            "package": "phpdocumentor/reflection-common",
            "version": "2.2.0",
            "libyears": 5.37,
            "at_least": false
        },
        "packages": 200
    },
    "baseline": null,
    "gate": {
        "fails": false,
        "tripped_by": [],
        "fail_on_applied": true,
        "reaching": 0,
        "failing": 0,
        "exempt": {
            "baseline": 0
        },
        "standing": {
            "known": 0,
            "new": 0,
            "worsened": 0,
            "none": 0
        },
        "failing_packages": [],
        "failing_by": [],
        "not_applied": [],
        "text": "gate: off · --fail-on=none (default) · target php 8.4 (--target-php)"
    },
    "security": {
        "check": "complete",
        "packages": {
            "vulnerable": 7,
            "unchecked": 0,
            "ignored": 0,
            "clear": 193
        },
        "advisories": {
            "counted": 10,
            "ignored": 0
        },
        "severities": {
            "critical": 0,
            "high": 1,
            "medium": 5,
            "unrated": 4,
            "low": 0
        },
        "fixes": {
            "update": 4,
            "upgrade": 2,
            "raise-php": 0,
            "unknown": 0,
            "blocked": 0,
            "none": 1
        },
        "update_now": [
            "symfony/expression-language",
            "symfony/http-foundation",
            "phpseclib/phpseclib",
            "symfony/yaml"
        ],
        "update_now_command": [
            "composer",
            "update",
            "symfony/expression-language:^5.4.54",
            "symfony/http-foundation:^5.4.54",
            "phpseclib/phpseclib:^3.0.57",
            "symfony/yaml:^6.4.45"
        ],
        "fix_unknown": 0
    },
    "data_date": "2026-10-26T08:46:29+00:00",
    "notes": [],
    "note_details": [],
    "findings": [
        {
            "package": "spomky-labs/otphp",
            "version": "v10.0.3",
            "branch": "10.x",
            "installed_php": {
                "requires": "^7.2|^8.0",
                "target_runs": true,
                "project_allows": true
            },
            "verdict": "high",
            "priority": "high",
            "lead": "left-behind",
            "rank": 4,
            "flags": [
                {
                    "id": "left-behind",
                    "role": "lead",
                    "baseline": null,
                    "signal_ids": [
                        "S8"
                    ],
                    "degree": null,
                    "headline": {
                        "unit": "years",
                        "value": 4.6,
                        "source": "branch"
                    },
                    "summary": "branch 10.x last released 2022-03-17 (4.6 years ago); 11.x released 11.5.0 (2026-06-06)"
                },
                {
                    "id": "vulnerable",
                    "role": "security",
                    "baseline": null,
                    "signal_ids": [
                        "S9"
                    ],
                    "degree": null,
                    "headline": {
                        "unit": "advisories",
                        "value": 2,
                        "source": null
                    },
                    "summary": "2 advisories: 1 high, 1 medium — none fixed on 10.x; worst: PKSA-kbc7-dq62-pt7d Unbounded digits parameter in a provisioning URI triggers an uncaught DivisionByZeroError in OTP generation"
                }
            ],
            "score": {
                "model": 1,
                "total": 24,
                "exact": 24,
                "rounded_down": false,
                "band": {
                    "floor": 16,
                    "next": "critical",
                    "to_next": 8
                },
                "decided_by": "security",
                "parts": {
                    "maintenance": {
                        "status": "counted",
                        "contribution": 8,
                        "alone": {
                            "total": 8,
                            "verdict": "medium"
                        }
                    },
                    "security": {
                        "status": "counted",
                        "contribution": 16,
                        "alone": {
                            "total": 16,
                            "verdict": "high"
                        },
                        "of": 2,
                        "tied": []
                    }
                },
                "terms": [
                    {
                        "part": "maintenance",
                        "flag": "left-behind",
                        "role": "lead",
                        "weight": 16,
                        "divisor": 1,
                        "points": 16,
                        "contribution": 8
                    },
                    {
                        "part": "security",
                        "flag": "vulnerable",
                        "role": "security",
                        "advisory": "PKSA-kbc7-dq62-pt7d",
                        "severity": "high",
                        "fix_kind": "upgrade",
                        "weight": 16,
                        "multiplier": 1,
                        "points": 16,
                        "contribution": 16
                    }
                ],
                "modifiers": [
                    {
                        "reason": "transitive",
                        "applies_to": "maintenance",
                        "divide_by": 2,
                        "before": 16,
                        "after": 8
                    }
                ],
                "accepted": [],
                "without": [
                    {
                        "remove": {
                            "kind": "flag",
                            "id": "left-behind"
                        },
                        "revealed": [],
                        "total": 16,
                        "verdict": "high",
                        "lead": null,
                        "deciding_advisory": "PKSA-kbc7-dq62-pt7d",
                        "at_least": false
                    },
                    {
                        "remove": {
                            "kind": "flag",
                            "id": "vulnerable"
                        },
                        "revealed": [],
                        "total": 8,
                        "verdict": "medium",
                        "lead": "left-behind",
                        "deciding_advisory": null,
                        "at_least": false
                    },
                    {
                        "remove": {
                            "kind": "advisory",
                            "id": "PKSA-kbc7-dq62-pt7d"
                        },
                        "revealed": [],
                        "total": 16,
                        "verdict": "high",
                        "lead": "left-behind",
                        "deciding_advisory": "PKSA-qv5y-crcz-9nxw",
                        "at_least": false
                    }
                ],
                "text": "24 = left-behind 16 ÷ 2 transitive + vulnerable 16 [high advisory]"
            },
            "next_step": {
                "kind": "require",
                "source": "vulnerable",
                "merged": [],
                "fix_kind": "upgrade",
                "reason": null,
                "replacement": null,
                "quiet_years": null,
                "to_branch": "11.x",
                "version": "11.4.3",
                "newest": "11.5.0",
                "constraint": "^11.4.3",
                "latest": null,
                "php_check": {
                    "requires": ">=8.1",
                    "project_allows": true,
                    "target_runs": true,
                    "raise_to": null,
                    "raise_size": null
                },
                "crosses_major": true,
                "held_by": [
                    {
                        "source": "root",
                        "package": null,
                        "version": null,
                        "link": "root-require",
                        "target": "scheb/2fa-bundle",
                        "constraint": "^5.13.2",
                        "holder": null
                    },
                    {
                        "source": "package",
                        "package": "scheb/2fa-google-authenticator",
                        "version": "v5.13.2",
                        "link": "require",
                        "target": null,
                        "constraint": "^9.1|^10.0",
                        "holder": {
                            "verdict": "high",
                            "lead": "left-behind",
                            "flag_ids": [
                                "left-behind"
                            ],
                            "direct": true,
                            "replacement": null,
                            "next_step": {
                                "kind": "require",
                                "to_branch": "7.x",
                                "constraint": "^7.14",
                                "check": {
                                    "result": "refused",
                                    "form": null,
                                    "reason": null,
                                    "installs": null
                                }
                            }
                        }
                    },
                    {
                        "source": "package",
                        "package": "scheb/2fa-google-authenticator",
                        "version": "5.x-dev",
                        "link": "require",
                        "target": "scheb/2fa-bundle",
                        "constraint": "self.version",
                        "holder": null
                    }
                ],
                "clears": [
                    {
                        "kind": "flag",
                        "id": "left-behind",
                        "basis": "branch_releasing"
                    },
                    {
                        "kind": "advisory",
                        "id": "PKSA-kbc7-dq62-pt7d",
                        "basis": "outside_range"
                    },
                    {
                        "kind": "advisory",
                        "id": "PKSA-qv5y-crcz-9nxw",
                        "basis": "outside_range"
                    }
                ],
                "unverified": [],
                "adds": [],
                "leaves": [],
                "clears_all": true,
                "if_applied": {
                    "total": 0,
                    "verdict": "ok",
                    "lead": null,
                    "deciding_advisory": null,
                    "at_most": false,
                    "assumes": [
                        "clears_hold",
                        "composer_resolves"
                    ]
                },
                "check": {
                    "result": "refused",
                    "form": null,
                    "reason": null,
                    "installs": null
                },
                "moves_with": [],
                "composer_moves_because": [
                    "holders",
                    "crosses_major"
                ],
                "commands": [
                    [
                        "composer",
                        "why-not",
                        "spomky-labs/otphp",
                        "11.4.3",
                        "--locked"
                    ]
                ],
                "through": [
                    {
                        "package": "scheb/2fa-google-authenticator",
                        "installed_version": "v5.13.2",
                        "installed_requires": true,
                        "newest_stable": {
                            "version": "v8.6.1",
                            "released": "2026-01-24T13:27:55+00:00"
                        },
                        "newest_requires": true,
                        "newest_requires_unmeasured": null,
                        "local": false,
                        "newest_is_installed": false
                    }
                ],
                "also": [],
                "text": "move spomky-labs/otphp to ^11.4.3 through scheb/2fa-google-authenticator — clears left-behind and 2 advisories; 11.x needs php >=8.1, which require.php allows; the target php 8.4 runs it; held by composer.json (requires scheb/2fa-bundle ^5.13.2) and scheb/2fa-google-authenticator v5.13.2 (requires ^9.1|^10.0; itself left-behind) and scheb/2fa-google-authenticator 5.x-dev (requires scheb/2fa-bundle self.version (5.x-dev)); the packages that hold it may need to move too; a new major brings its own requirements; Composer's solver refuses it against the lock"
            },
            "security": {
                "status": "vulnerable",
                "check": "complete",
                "complete": true,
                "unchecked_reason": null,
                "records": 2,
                "feeds": [
                    {
                        "composer_repository": "composer repo (https://repo.packagist.org)",
                        "registry": "packagist.org",
                        "answer": "answered",
                        "records": 2,
                        "reason": null
                    }
                ],
                "worst": "high",
                "counts": {
                    "critical": 0,
                    "high": 1,
                    "medium": 1,
                    "unrated": 0,
                    "low": 0
                },
                "ignored": [],
                "ignored_count": 0,
                "fix_kind": "upgrade",
                "installed_branch_fixes": {
                    "fixed": 0,
                    "unknown": 0,
                    "of": 2,
                    "fix_kind": null,
                    "lowest": null,
                    "newest": "v10.0.3",
                    "held_by": [],
                    "if_applied": null,
                    "check": null
                },
                "move_in": "next_step",
                "gets": null,
                "partial": null
            },
            "checks_missing": [],
            "checks_skipped": [],
            "unchecked_accepted": [],
            "maintenance_judged": true,
            "metadata": {
                "status": "read",
                "reason": null,
                "message": null
            },
            "activity": {
                "status": "read",
                "reason": null,
                "forge": "github",
                "host": "github.com",
                "repo": "Spomky-Labs/otphp",
                "event": "push",
                "archived": false,
                "fetched_at": "2026-10-26T08:46:29+00:00",
                "cached_at": null
            },
            "allowlist": null,
            "abandoned_ignored": null,
            "from_composer_repository": true,
            "origin": {
                "kind": "packagist",
                "registry": "packagist.org",
                "package_url": "https://packagist.org/packages/spomky-labs/otphp",
                "local": false
            },
            "replacement": null,
            "replacement_url": null,
            "note": null,
            "libyears_unmeasured": null,
            "libyears_at_least": false,
            "direct": false,
            "dev": false,
            "reach": "transitive",
            "chain": [
                "scheb/2fa-google-authenticator",
                "spomky-labs/otphp"
            ],
            "depth": 1,
            "depth_unmeasured": null,
            "named_in": [],
            "direct_dependents": [
                "scheb/2fa-google-authenticator"
            ],
            "signals": [
                {
                    "id": "S8",
                    "level": "warn",
                    "summary": "branch 10.x last released 2022-03-17 (4.6 years ago); 11.x released 11.5.0 (2026-06-06)",
                    "data": {
                        "branch": "10.x",
                        "branch_last_release": "2022-03-17T08:00:35+00:00",
                        "branch_last_version": "v10.0.3",
                        "years": 4.6,
                        "newest_branch": "11.x",
                        "newest_version": "11.5.0",
                        "newest_release": "2026-06-06T23:41:24+00:00",
                        "newest_php": ">=8.1",
                        "newest_within_reach": true,
                        "floor_php": null,
                        "floor_source": null,
                        "reachable_branch": "11.x",
                        "reachable_version": "11.5.0",
                        "reachable_release": "2026-06-06T23:41:24+00:00",
                        "suggested_constraint": "^11.5",
                        "dated_by": null,
                        "reachable_php": ">=8.1",
                        "reachable_admits": {
                            "project": true,
                            "target": true
                        },
                        "newest_years": 0.4,
                        "reachable_years": 0.4
                    }
                },
                {
                    "id": "S9",
                    "level": "high",
                    "summary": "2 security advisories affect v10.0.3 (PKSA-kbc7-dq62-pt7d, PKSA-qv5y-crcz-9nxw); fixed in 11.4.3",
                    "data": {
                        "advisories": [
                            {
                                "id": "PKSA-kbc7-dq62-pt7d",
                                "cve": null,
                                "title": "Unbounded digits parameter in a provisioning URI triggers an uncaught DivisionByZeroError in OTP generation",
                                "link": "https://github.com/Spomky-Labs/otphp/security/advisories/GHSA-g7m4-839x-ch6v",
                                "reported_at": "2026-05-31T09:06:37+00:00",
                                "severity": "high",
                                "severity_published": "high",
                                "affected_versions": "<11.4.3",
                                "counted": true,
                                "points": 16,
                                "deciding": true,
                                "fix": {
                                    "kind": "upgrade",
                                    "to_branch": "11.x",
                                    "version": "11.4.3",
                                    "newest": "11.5.0",
                                    "on_installed_branch": false,
                                    "php": ">=8.1",
                                    "admits_target_php": true,
                                    "admits_project_php": true,
                                    "held_by": [
                                        {
                                            "source": "root",
                                            "package": null,
                                            "version": null,
                                            "link": "root-require",
                                            "target": "scheb/2fa-bundle",
                                            "constraint": "^5.13.2",
                                            "holder": null
                                        },
                                        {
                                            "source": "package",
                                            "package": "scheb/2fa-google-authenticator",
                                            "version": "v5.13.2",
                                            "link": "require",
                                            "target": null,
                                            "constraint": "^9.1|^10.0",
                                            "holder": {
                                                "verdict": "high",
                                                "lead": "left-behind",
                                                "flag_ids": [
                                                    "left-behind"
                                                ],
                                                "direct": true,
                                                "replacement": null,
                                                "next_step": {
                                                    "kind": "require",
                                                    "to_branch": "7.x",
                                                    "constraint": "^7.14",
                                                    "check": {
                                                        "result": "refused",
                                                        "form": null,
                                                        "reason": null,
                                                        "installs": null
                                                    }
                                                }
                                            }
                                        },
                                        {
                                            "source": "package",
                                            "package": "scheb/2fa-google-authenticator",
                                            "version": "5.x-dev",
                                            "link": "require",
                                            "target": "scheb/2fa-bundle",
                                            "constraint": "self.version",
                                            "holder": null
                                        }
                                    ],
                                    "reason": null
                                },
                                "baseline": null
                            },
                            {
                                "id": "PKSA-qv5y-crcz-9nxw",
                                "cve": null,
                                "title": "Mass-assignment in Factory::loadFromProvisioningUri lets a hostile provisioning URI corrupt OTP state or leak an uncaught TypeError",
                                "link": "https://github.com/Spomky-Labs/otphp/security/advisories/GHSA-2jx3-65f3-xr8r",
                                "reported_at": "2026-05-31T09:08:33+00:00",
                                "severity": "medium",
                                "severity_published": "medium",
                                "affected_versions": "<11.4.3",
                                "counted": true,
                                "points": 8,
                                "deciding": false,
                                "fix": {
                                    "kind": "upgrade",
                                    "to_branch": "11.x",
                                    "version": "11.4.3",
                                    "newest": "11.5.0",
                                    "on_installed_branch": false,
                                    "php": ">=8.1",
                                    "admits_target_php": true,
                                    "admits_project_php": true,
                                    "held_by": [
                                        {
                                            "source": "root",
                                            "package": null,
                                            "version": null,
                                            "link": "root-require",
                                            "target": "scheb/2fa-bundle",
                                            "constraint": "^5.13.2",
                                            "holder": null
                                        },
                                        {
                                            "source": "package",
                                            "package": "scheb/2fa-google-authenticator",
                                            "version": "v5.13.2",
                                            "link": "require",
                                            "target": null,
                                            "constraint": "^9.1|^10.0",
                                            "holder": {
                                                "verdict": "high",
                                                "lead": "left-behind",
                                                "flag_ids": [
                                                    "left-behind"
                                                ],
                                                "direct": true,
                                                "replacement": null,
                                                "next_step": {
                                                    "kind": "require",
                                                    "to_branch": "7.x",
                                                    "constraint": "^7.14",
                                                    "check": {
                                                        "result": "refused",
                                                        "form": null,
                                                        "reason": null,
                                                        "installs": null
                                                    }
                                                }
                                            }
                                        },
                                        {
                                            "source": "package",
                                            "package": "scheb/2fa-google-authenticator",
                                            "version": "5.x-dev",
                                            "link": "require",
                                            "target": "scheb/2fa-bundle",
                                            "constraint": "self.version",
                                            "holder": null
                                        }
                                    ],
                                    "reason": null
                                },
                                "baseline": null
                            }
                        ],
                        "releases_read": true,
                        "complete": true
                    }
                }
            ],
            "evidence": "left-behind: branch 10.x last released 2022-03-17 (4.6 years ago); 11.x released 11.5.0 (2026-06-06); vulnerable: 2 advisories: 1 high, 1 medium — none fixed on 10.x; worst: PKSA-kbc7-dq62-pt7d Unbounded digits parameter in a provisioning URI triggers an uncaught DivisionByZeroError in OTP generation",
            "allowlist_reason": null,
            "data_date": "2026-10-26T08:46:29+00:00",
            "libyears": 4.22,
            "age": {
                "installed": {
                    "at": "2022-03-17T08:00:35+00:00",
                    "years": 4.6,
                    "dated_by": null
                },
                "installed_unmeasured": null,
                "release": {
                    "version": "11.5.0",
                    "at": "2026-06-06T23:41:24+00:00",
                    "years": 0.4,
                    "level": null,
                    "dated_by": null
                },
                "release_unmeasured": null,
                "branch_release": {
                    "version": "v10.0.3",
                    "at": "2022-03-17T08:00:35+00:00",
                    "years": 4.6,
                    "level": "warn",
                    "dated_by": null
                },
                "branch_release_unmeasured": null,
                "push": {
                    "at": "2026-10-22T18:36:19+00:00",
                    "years": 0,
                    "level": null
                },
                "push_unmeasured": null
            },
            "baseline": null,
            "gate": {
                "reaches_fail_on": false,
                "fails": false,
                "exempt_by": null,
                "by": [],
                "basis": null
            }
        }
    ]
}
```

- Every finding carries the keys the one shown carries.
  [schema.md](schema.md#what-the-report-schema-types) defines each.

- `score.terms` holds each term of the score line, and `score.parts` the maintenance and security
  parts. `run.score_model` holds every number of the [score model](verdicts/model-1.md).

- `next_step` is the `do:` line as data: the move, the constraint it asks for, what holds it, and
  its `check`. `run.move_check` says which Composer solved the moves.

- `exposure` is the `pulled in by:` line in full, most first. `exposure_rule` and `unattributed`
  belong to the same count
  ([shared packages and `unattributed`](verdicts.md#shared-packages-and-unattributed)).

- `gate.fails` is false: under `fail_on` `none` no finding reaches a value, so the run exits `0`
  ([exit codes](ci.md#exit-codes)).

- `notes` and `note_details` are empty: every source answered and no cap applied
  ([run notes](notes.md)).

## A clean run

Recorded with the same version, on the same date, on a fresh `cakephp/app` skeleton whose lock has
nothing to grade. The run exited `0` ([exit codes](ci.md#exit-codes)).

```text
20 packages checked · nothing flagged
libyears: 0.0 behind across all 20 packages · 0.0 from direct requirements ·
furthest behind psr/container 2.0.2 at 0.0
advisories: 20 packages asked · packagist.org answered (25 on record for 3 packages)
moves: checked with the solver of Composer 2.10.3 against the lock; ext-*, lib-*, php-debug, php-ipv6 and php-zts requirements not checked
php: without require-dev the lock installs on PHP 8.2 to 8.5, and with require-dev on PHP 8.4 to 8.5
gate: off · --fail-on=none (default) · target php 8.4 (--target-php)
Data as of 2026-10-26 (package repositories, repository hosts) · score model 1. Run composer lockrot --format=json for
details.
```

## Related

- [verdicts.md](verdicts.md) — what each flag, score and verdict in this output means
- [ci.md](ci.md) — every other output format, and the exit codes
- [baseline.md](baseline.md) — accept these findings so CI fails only on new ones
- [internals.md](internals.md) — where each date and the footer's sources come from
