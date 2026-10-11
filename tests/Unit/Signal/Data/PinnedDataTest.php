<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Data;

use Lockrot\Json\JsonWriter;
use Lockrot\Signal\Data\PinnedData;
use PHPUnit\Framework\TestCase;

/** The S6 data of prestashop/ps_apiresources in the PrestaShop fixture app, with `--dev`. */
final class PinnedDataTest extends TestCase
{
    public function testItWritesTheS6KeysInOrderWithTheirNulls(): void
    {
        $data = new PinnedData('dev-41542-extra-property-definition-endpoints', 'branch_snapshot', null, null, null, null, '2026-09-10T10:17:17+00:00', null);

        self::assertSame('{"version":"dev-41542-extra-property-definition-endpoints","reason":"branch_snapshot","has_stable_release":null,"last_stable_release":null,"last_stable_version":null,"last_stable_dated_by":null,"snapshot_time":"2026-09-10T10:17:17+00:00","tag_relation":null}', JsonWriter::encode($data, \JSON_UNESCAPED_SLASHES));
        self::assertSame('dev-41542-extra-property-definition-endpoints', $data->version());
        self::assertSame('branch_snapshot', $data->reason());
    }

    public function testItWritesTheReleaseFactsOfARepositoryWithATag(): void
    {
        $data = new PinnedData('dev-main', 'branch_snapshot', true, '2024-01-02T00:00:00+00:00', '2.1.0', 'acme/monorepo', '2025-01-02T00:00:00+00:00', 'older');

        self::assertSame('{"version":"dev-main","reason":"branch_snapshot","has_stable_release":true,"last_stable_release":"2024-01-02T00:00:00+00:00","last_stable_version":"2.1.0","last_stable_dated_by":"acme/monorepo","snapshot_time":"2025-01-02T00:00:00+00:00","tag_relation":"older"}', JsonWriter::encode($data, \JSON_UNESCAPED_SLASHES));
    }
}
