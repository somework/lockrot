### lockrot: 1 of 2 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `guzzlehttp/psr7` | 1.9.1 | left-behind · vulnerable | `16 = left-behind 16 ÷ 2 transitive + vulnerable 8 [medium advisory]`<br>**Do:** raise require.php to \>=7.2.5, then move guzzlehttp/psr7 to ^2.13 through guzzlehttp/guzzle — clears left-behind and 4 advisories; 2.x needs php ^7.2.5 \|\| ^8.0, require.php allows 7.2 (a patch raise); the target php 8.4 runs it; held by guzzlehttp/guzzle 6.5.8 (requires ^1.9); the packages that hold it may need to move too; a new major brings its own requirements, which lockrot does not solve<br>*left-behind:* branch 1.x last released 2023-04-17 (3.5 years ago); 3.x released 3.1.0 (2026-08-24), needs php ^7.4 \|\| ^8.0 above the project's php ^7.2 \|\| ^8.0.0; no releasing branch within reach<br>*vulnerable:* 4 advisories: 4 medium — none fixed on 1.x; worst: CVE-2026-55766 CRLF injection in HTTP start-line serialization | guzzlehttp/guzzle |

flags: left-behind 1 · vulnerable 1 · 1 package carries more than one
depth: 1 of 1 flagged package is named by no line of composer.json · 1 hop 1 · deepest: `guzzlehttp/psr7`, 1 hop (`guzzlehttp/guzzle` › `guzzlehttp/psr7`)
security: 1 vulnerable package · 4 advisories: 4 medium
libyears: 3.4 behind across the one package · 0.0 from direct requirements · furthest behind guzzlehttp/psr7 1.9.1 at 3.4
pulled in by: `guzzlehttp/guzzle` 1
advisories: 1 package asked · packagist.org answered (4 on record for 1 package)

<sub>2 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
