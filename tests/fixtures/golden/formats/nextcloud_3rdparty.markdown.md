### lockrot: 9 of 94 packages flagged — critical 2 · high 1 · medium 3 · low 3

**gate: fails** · --fail-on=high · 3 findings fail it · advisories not applied to 94 packages: not checked (Composer below 2.4) · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **critical** | `bantu/ini-get-wrapper` | v1.0.1 | silent | `32 = silent 32`<br>**Do:** find a maintained alternative: nothing released for 12.0 years<br>*silent:* last release 2014-09-15 (12.0 years ago); last push 2015-03-05 (11.5 years ago) | direct |
| **critical** | `microsoft/azure-storage-blob` | 1.5.4 | abandoned | `32 = abandoned 32`<br>**Do:** find a replacement (none named)<br>*abandoned:* marked abandoned by its registry; its repository is archived on GitHub; last release 2022-09-02 (4.0 years ago); last push 2023-07-18 (3.2 years ago)<br>pulls in 1 flagged package: microsoft/azure-storage-common (stale) | direct |
| **high** | `pear/console_getopt` | v1.4.3 | silent | `16 = silent 32 ÷ 2 transitive`<br>**Do:** find a maintained alternative: nothing released for 6.8 years; it comes in through pear/archive\_tar, pear/pear-core-minimal: replace or update pear/archive\_tar, or replace pear/pear-core-minimal; the newest release of pear/pear-core-minimal (v1.10.18) still requires it<br>*silent:* last release 2019-11-20 (6.8 years ago); last push 2021-08-11 (5.1 years ago) | pear/archive\_tar, pear/pear-core-minimal |
| medium | `deepdiver1975/tarstreamer` | v2.1.0 | stale | `8 = stale 8`<br>**Do:** —<br>*stale:* last release 2023-06-16 (3.2 years ago) | direct |
| medium | `kornrunner/blurhash` | v1.2.2 | stale | `8 = stale 8`<br>**Do:** —<br>*stale:* last release 2022-07-13 (4.2 years ago) | direct |
| medium | `mexitek/phpcolors` | v1.0.4 | stale | `8 = stale 8`<br>**Do:** —<br>*stale:* last release 2021-11-26 (4.8 years ago); last push 2022-07-29 (4.1 years ago) | direct |
| low | `cweagans/composer-configurable-plugin` | 2.0.0 | stale | `4 = stale 8 ÷ 2 transitive`<br>**Do:** —<br>*stale:* last release 2023-02-12 (3.6 years ago) | cweagans/composer-patches |
| low | `microsoft/azure-storage-common` | 1.5.2 | stale | `4 = stale 8 ÷ 2 transitive`<br>**Do:** —<br>*stale:* last release 2021-10-09 (4.9 years ago); last push 2023-07-18 (3.2 years ago) | microsoft/azure-storage-blob |
| low | `pear/pear_exception` | v1.0.2 | stale | `4 = stale 8 ÷ 2 transitive`<br>**Do:** —<br>*stale:* last release 2021-03-21 (5.5 years ago) | pear/archive\_tar, pear/pear-core-minimal |

flags: abandoned 1 · silent 2 · stale 6
depth: 4 of 9 flagged packages are named by no line of composer.json · direct 5 · 1 hop 4 · deepest: `cweagans/composer-configurable-plugin`, `microsoft/azure-storage-common`, `pear/console_getopt` and 1 more, 1 hop (`cweagans/composer-patches` › `cweagans/composer-configurable-plugin`)
security: no vulnerable package · advisories not checked
libyears: at least 47.5 behind across all 94 packages · 13.3 from direct requirements · furthest behind punic/punic 3.8.1 at 3.5
pulled in by: `pear/archive_tar` 2 · `pear/pear-core-minimal` 2 · `cweagans/composer-patches` 1 · `microsoft/azure-storage-blob` 1
advisories: 0 packages asked, 94 not asked
php: the lock installs on PHP 8.3 to 8.5; Composer checks it at 8.3.0 from config.platform.php

- note: Repository activity not checked for 3 packages on hosts lockrot cannot ask: codeberg.org (3). A host that runs GitLab is asked once Composer's gitlab-domains lists it; lockrot cannot ask other forge software (Forgejo, Gitea) or a Subversion host.

<sub>94 packages checked — data as of 2026-09-14 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
