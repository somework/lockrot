### lockrot: nothing flagged in 5 packages

**gate: passes** · --fail-on=high,unchecked · no finding fails it; 1 reaches it and the baseline exempts it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| ok | `scheb/2fa-google-authenticator` | v8.6.1 | ok | `0 (baseline: known)`<br>`gate 0 = min(0, 2 × 0) (the new facts bound it); new: 0`<br>checks missing: S2 (undated releases) | pimcore/pimcore, pimcore/quill-bundle, pimcore/studio-backend-bundle and 1 more |

libyears: 0.0 behind across the one package\
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)\
php: the lock installs on PHP 8.4 to 8.5\
baseline: 1 known · 0 new · 0 worsened · 0 stale (lockrot-baseline.json)

<sub>5 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run lockrot with `--format=json` for details.</sub>
