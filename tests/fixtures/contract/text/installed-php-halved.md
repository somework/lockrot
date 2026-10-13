### lockrot: 1 of 2 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `web-auth/metadata-service` | v2.1.7 | abandoned · left-behind | `18 = (abandoned 32 + left-behind 4 [¼ of 16]) ÷ 2 transitive`<br>**Do:** replace with web-auth/webauthn-lib (named by its registry); it comes in through web-auth/webauthn-lib: replace or update that package; the newest release of web-auth/webauthn-lib (5.3.9) no longer requires it<br>*abandoned:* marked abandoned by its registry, replacement: web-auth/webauthn-lib<br>*left-behind:* branch 2.x last released 2019-09-04 (7.1 years ago); 4.x released 4.9.1 (2024-07-11) | web-auth/webauthn-lib |

flags: abandoned 1 · left-behind 1 · 1 package carries more than one
depth: 1 of 1 flagged package is named by no line of composer.json · 1 hop 1 · deepest: `web-auth/metadata-service`, 1 hop (`web-auth/webauthn-lib` › `web-auth/metadata-service`)
libyears: 4.9 behind across the one package · 0.0 from direct requirements · furthest behind web-auth/metadata-service v2.1.7 at 4.9
pulled in by: `web-auth/webauthn-lib` 1
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)

<sub>2 packages checked — data as of 2026-10-14 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
