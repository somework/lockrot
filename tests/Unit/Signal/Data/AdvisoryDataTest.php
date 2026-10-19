<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Data;

use Lockrot\Data\Repository\StableRelease;
use Lockrot\Json\JsonWriter;
use Lockrot\Score\CountedAdvisory;
use Lockrot\Score\Standing;
use Lockrot\Security\Candidate;
use Lockrot\Security\Fix;
use Lockrot\Security\Holder;
use Lockrot\Signal\Data\AdvisoryData;
use Lockrot\Signal\Data\AdvisoryRow;
use Lockrot\Signal\PhpFloor;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\TestCase;

/** The deciding S9 row of twig/twig in case A of tests/fixtures/flags/cases.json. */
final class AdvisoryDataTest extends TestCase
{
    private const FIX = '{"kind":"raise-php","to_branch":"3.x","version":"v3.26.0","newest":"v3.30.0","on_installed_branch":false,"php":">=8.1.0","admits_target_php":true,"admits_project_php":false,"held_by":[{"source":"root","package":null,"version":null,"link":"require","target":null,"constraint":"^2.0","holder":null},{"source":"package","package":"symfony/twig-bridge","version":"v3.4.47","link":"require","target":null,"constraint":"^1.41|^2.10","holder":null}],"reason":null}';
    private const ROW = '{"id":"PKSA-h8hf-ytnd-5t9q","cve":"CVE-2026-46633","title":"PHP code injection via `{% use %}` template name","link":"https://symfony.com/cve-2026-46633","reported_at":"2026-05-20T08:00:00+00:00","severity":"critical","severity_published":"critical","affected_versions":">=1.0.0,<2.0.0|>=2.0.0,<3.0.0|>=3.0.0,<3.26.0","counted":true,"points":32,"deciding":DECIDING,"fix":'.self::FIX.',"baseline":null}';

    public function testARowWritesItsKeysInOrderWithDecidingAfterPoints(): void
    {
        $row = self::row();

        self::assertSame(str_replace('DECIDING', 'false', self::ROW), JsonWriter::encode($row, \JSON_UNESCAPED_SLASHES));
        self::assertSame(str_replace('DECIDING', 'true', self::ROW), JsonWriter::encode($row->withDeciding(true), \JSON_UNESCAPED_SLASHES));
        self::assertFalse($row->isDeciding(), 'marking returns a copy');
        self::assertTrue($row->withDeciding(true)->isDeciding());
        self::assertSame('PKSA-h8hf-ytnd-5t9q', $row->id());
        self::assertSame('CVE-2026-46633', $row->cve());
        self::assertSame('PHP code injection via `{% use %}` template name', $row->title());
        self::assertSame('critical', $row->severity());
        self::assertSame(32, $row->points());
        self::assertSame(Fix::RAISE_PHP, $row->fix()->kind());
    }

    public function testTheDataWritesItsRowsAndMarksOnlyTheDecidingOne(): void
    {
        $other = new AdvisoryRow('PKSA-other', null, null, null, null, 'unrated', null, null, 8, Fix::unknown(Fix::RELEASES_UNKNOWN));
        $row = self::row();
        $data = new AdvisoryData([$row, $other], true, false);

        self::assertSame([$row, $other], $data->rows());
        self::assertSame(
            '{"advisories":['.str_replace('DECIDING', 'true', self::ROW).',{"id":"PKSA-other","cve":null,"title":null,"link":null,"reported_at":null,"severity":"unrated","severity_published":null,"affected_versions":null,"counted":true,"points":8,"deciding":false,"fix":{"kind":"unknown","to_branch":null,"version":null,"newest":null,"on_installed_branch":null,"php":null,"admits_target_php":null,"admits_project_php":null,"held_by":[],"reason":"releases_unknown"},"baseline":null}],"releases_read":true,"complete":false}',
            JsonWriter::encode($data->withDeciding('PKSA-h8hf-ytnd-5t9q'), \JSON_UNESCAPED_SLASHES)
        );
        self::assertSame([false, false], array_map(static fn (AdvisoryRow $row): bool => $row->isDeciding(), $data->withDeciding(null)->rows()));
        self::assertSame([$row, $other], $data->rows(), 'marking returns a copy');
    }

    public function testTheBaselineStateIsSetOnACopyOfEachRow(): void
    {
        $row = self::row();
        $data = new AdvisoryData([$row], true, true);
        $standing = Standing::newPackage([], [new CountedAdvisory('PKSA-h8hf-ytnd-5t9q', 'critical', 'raise-php')]);

        $marked = $data->withBaseline($standing);

        self::assertNull(JsonPath::decoded($data->rows()[0])['baseline'], 'the data keeps its rows');
        self::assertNull(JsonPath::decoded($row)['baseline'], 'the row keeps its state');
        self::assertSame(['state' => 'new', 'since' => null, 'covered_by' => null], JsonPath::decoded($marked->rows()[0])['baseline']);
        self::assertNull(JsonPath::decoded($marked->withBaseline(null)->rows()[0])['baseline'], 'no state without a standing');
    }

    public function testAFixAndAHolderWriteTheS9Shapes(): void
    {
        self::assertSame(self::FIX, JsonWriter::encode(self::row()->fix(), \JSON_UNESCAPED_SLASHES));
        self::assertSame('{"kind":"none","to_branch":null,"version":null,"newest":null,"on_installed_branch":null,"php":null,"admits_target_php":null,"admits_project_php":null,"held_by":[],"reason":"no_release_outside_range"}', JsonWriter::encode(Fix::none(), 0));
    }

    private static function row(): AdvisoryRow
    {
        $holders = [new Holder(Holder::ROOT, 'acme/app', null, Holder::REQUIRE, '^2.0'), new Holder(Holder::PACKAGE, 'symfony/twig-bridge', 'v3.4.47', Holder::REQUIRE, '^1.41|^2.10')];
        $fix = Fix::to(new Candidate(new StableRelease('3.26.0.0', 'v3.26.0', null, '>=8.1.0', false), '3', Fix::RAISE_PHP, $holders), 'v3.30.0', '2', new PhpFloor('8.4', '>=7.2.5'));

        return new AdvisoryRow('PKSA-h8hf-ytnd-5t9q', 'CVE-2026-46633', 'PHP code injection via `{% use %}` template name', 'https://symfony.com/cve-2026-46633', '2026-05-20T08:00:00+00:00', 'critical', 'critical', '>=1.0.0,<2.0.0|>=2.0.0,<3.0.0|>=3.0.0,<3.26.0', 32, $fix);
    }
}
