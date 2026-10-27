### lockrot: 1 of 4 packages flagged — low 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| low | `symfony/yaml` | v5.3.14 | vulnerable | `2 = vulnerable 2 [low advisory]`<br>**Do:** move symfony/yaml to ^5.4.52 through incenteev/composer-parameter-handler, nelmio/api-doc-bundle, php-amqplib/rabbitmq-bundle — clears 3 advisories; 5.x needs php \>=7.2.5, which require.php allows; the target php 8.4 runs it; held by incenteev/composer-parameter-handler v2.1.5 (conflicts with ^5.4); the packages that hold it may need to move too; Composer's solver refuses it against the lock<br>*vulnerable:* 3 advisories: 3 low — all fixed on 5.x (upgrade); worst: CVE-2026-45133 YAML Parser Stack Exhaustion via Unbounded Recursion in Nested Blocks, Sequences, and Mappings<br>*age:* installed v5.3.14 released 2022-01-26 (4.7 years ago); 5.x last released v5.4.53 2026-05-20 (0.4 years ago); newest release v8.1.8 2026-09-26 (less than three weeks ago); last push 2026-10-01 (less than three weeks ago) | incenteev/composer-parameter-handler, nelmio/api-doc-bundle, php-amqplib/rabbitmq-bundle |

flags: vulnerable 1
depth: 1 of 1 flagged package is named by no line of composer.json · 1 hop 1 · deepest: `symfony/yaml`, 1 hop (`incenteev/composer-parameter-handler` › `symfony/yaml`)
security: 1 vulnerable package · 3 advisories: 3 low
libyears: 4.7 behind across the one package · 0.0 from direct requirements · furthest behind symfony/yaml v5.3.14 at 4.7
pulled in by: `incenteev/composer-parameter-handler` 1 · `nelmio/api-doc-bundle` 1 · `php-amqplib/rabbitmq-bundle` 1
advisories: 1 package asked · packagist.org answered (5 on record for 1 package)
moves: checked with the solver of Composer 2.10.3 against the lock; ext-\* requirements not checked
php: the lock installs on PHP 7.4 to 8.5

<sub>4 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
