### lockrot: 1 of 4 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `symfony/routing` | v4.4.41 | left-behind · vulnerable | `16 = left-behind 16 ÷ 2 transitive + vulnerable 8 [medium advisory]`<br>**Do:** move symfony/routing to ^5.4.53 through mautic/core-lib — clears left-behind and 2 advisories; 5.x needs php \>=7.2.5; the target php 8.4 runs it; held by mautic/core-lib 4.3.x-dev (requires \~4.4.0; itself pinned); the packages that hold it may need to move too; a new major brings its own requirements, which lockrot does not solve<br>*left-behind:* branch 4.x last released 2022-07-20 (4.2 years ago); 8.x released v8.1.8 (2026-09-28)<br>*vulnerable:* 2 advisories: 2 medium — none fixed on 4.x; worst: CVE-2026-48784 UrlGenerator Dot-Segment Encoding Skips Every Other Chained \`../\` or \`./\` → Generated URL Collapses Off-Route Under RFC 3986 Normalization | mautic/core-lib |

flags: left-behind 1 · vulnerable 1 · 1 package carries more than one
depth: 1 of 1 flagged package is named by no line of composer.json · 1 hop 1 · deepest: `symfony/routing`, 1 hop (`mautic/core-lib` › `symfony/routing`)
security: 1 vulnerable package · 2 advisories: 2 medium
libyears: 4.5 behind across the one package · 0.0 from direct requirements · furthest behind symfony/routing v4.4.41 at 4.5
pulled in by: `mautic/core-lib` 1
advisories: 1 package asked · packagist.org answered (13 on record for 4 packages)
php: the lock installs on PHP 7.4 to 8.0, so your target, 8.4, is excluded; `mautic/core-lib` excludes 8.1

- note: 1 package is not from a Composer repository: its release metadata and repository activity were not read

<sub>4 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
