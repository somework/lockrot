### lockrot: dependency rot in 9 of 94 packages

| Priority | Package | Version | Verdict | Evidence | Via |
|---|---|---|---|---|---|
| critical | `microsoft/azure-storage-blob` | 1.5.4 | **abandoned** | marked abandoned by its repository; repository archived on GitHub; last release 2022-09-02 (4.0 years ago); last push 2023-07-18 (3.2 years ago); pulls in 1 flagged package: microsoft/azure-storage-common (stale) | direct |
| critical | `bantu/ini-get-wrapper` | v1.0.1 | **silent** | last release 2014-09-15 (12.0 years ago); last push 2015-03-05 (11.5 years ago) | direct |
| high | `pear/console_getopt` | v1.4.3 | **silent** | last release 2019-11-20 (6.8 years ago); last push 2021-08-11 (5.1 years ago) | pear/pear-core-minimal, also via pear/archive\_tar |
| medium | `deepdiver1975/tarstreamer` | v2.1.0 | **stale** | last release 2023-06-16 (3.2 years ago) | direct |
| medium | `kornrunner/blurhash` | v1.2.2 | **stale** | last release 2022-07-13 (4.2 years ago) | direct |
| medium | `mexitek/phpcolors` | v1.0.4 | **stale** | last release 2021-11-26 (4.8 years ago); last push 2022-07-29 (4.1 years ago) | direct |
| low | `cweagans/composer-configurable-plugin` | 2.0.0 | **stale** | last release 2023-02-12 (3.6 years ago) | cweagans/composer-patches |
| low | `microsoft/azure-storage-common` | 1.5.2 | **stale** | last release 2021-10-09 (4.9 years ago); last push 2023-07-18 (3.2 years ago) | microsoft/azure-storage-blob |
| low | `pear/pear_exception` | v1.0.2 | **stale** | last release 2021-03-21 (5.5 years ago) | pear/pear-core-minimal, also via pear/archive\_tar |

libyears: 47.5 behind across all 94 packages · 13.3 from direct requirements · furthest behind punic/punic 3.8.1 at 3.5

pulled in by: pear/archive\_tar 2 · pear/pear-core-minimal 2 · cweagans/composer-patches 1 · microsoft/azure-storage-blob 1

- note: Repository activity not checked for 3 packages on hosts lockrot cannot ask: codeberg.org (3). A host that runs GitLab is asked once Composer's gitlab-domains lists it; lockrot cannot ask other forge software (Forgejo, Gitea) or a Subversion host.

<sub>94 packages checked · abandoned 1 · silent 2 · pinned 0 · left-behind 0 · old-promise 0 · stale 6 · unknown 0 · finished 16 · ok 69 — data as of 2026-09-14 (package repositories, repository hosts). Run `composer lockrot --format=json` for details.</sub>
