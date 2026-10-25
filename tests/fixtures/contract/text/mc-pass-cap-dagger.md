### lockrot: 1 of 2 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `symfony/mailer` | v6.4.27 | vulnerable | `16 = vulnerable 16 [high advisory]`<br>**Do:** require symfony/mailer ^6.4.40 — clears the advisory; 6.x needs php \>=8.1, which require.php allows; the target php 8.4 runs it; held by composer.json (requires ^6.4) and composer.json (requires acme/ml-widget ^1.0) and symfony/mailer v6.4.40, the release this move installs (requires acme/ml-transport ^2.0) and acme/ml-widget 1.0.0 (conflicts with acme/ml-transport \>=2.0); the packages that hold it may need to move too; not checked with Composer's solver (Composer refused each move before it), so it holds only if Composer resolves it<br>*vulnerable:* 1 advisory: 1 high — all fixed on 6.x (upgrade); advisory: CVE-2026-45068 Argument Injection in SendmailTransport via Dash-Prefixed Recipient Address<br>*age:* installed v6.4.27 released 2025-10-24 (0.9 years ago); 6.x last released v6.4.40 2026-07-04 (0.2 years ago), its newest release; last push 2026-09-15 (less than three weeks ago) | direct |

flags: vulnerable 1
depth: every flagged package is named in composer.json · direct 1
security: 1 vulnerable package · 1 advisory: 1 high
libyears: 0.7 behind across the one package · 0.7 from direct requirements · furthest behind symfony/mailer v6.4.27 at 0.7
advisories: 1 package asked · packagist.org answered (1 on record for 1 package)
moves: checked with the solver of Composer 2.10.3 against the lock; ext-\* requirements not checked
php: the lock installs on PHP 8.1 to 8.5

<sub>2 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
