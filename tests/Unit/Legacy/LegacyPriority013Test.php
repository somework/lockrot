<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Legacy;

use Lockrot\Legacy\Priority013;
use Lockrot\Tests\Support\CorpusFloor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The frozen priority rules over the corpus floor. Each finding gets the priority that report-1
 * recorded. The findings whose priority reaches a threshold that their grade does not reach are named.
 */
final class LegacyPriority013Test extends TestCase
{
    public function testEveryFindingGetsThePriorityThatReport1Recorded(): void
    {
        $checked = 0;
        foreach (CorpusFloor::reports() as $report) {
            foreach ($report['findings'] as $recorded) {
                ++$checked;
                self::assertSame($recorded['priority'], CorpusFloor::finding($recorded)->priority(), $report['name'].' '.$recorded['package']);
            }
        }
        self::assertGreaterThan(0, $checked);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function thresholds(): iterable
    {
        yield 'critical' => [Priority013::CRITICAL, [
            'joomla-cms-v4 enshrined/svg-sanitize 0.15.4',
            'phpbb symfony/http-kernel v3.4.49',
            'phpbb symfony/routing v3.4.47',
            'phpbb symfony/twig-bridge v3.4.47',
            'phpbb symfony/yaml v3.4.47',
            'wallabag enshrined/svg-sanitize 0.15.4',
            'wallabag symfony/dom-crawler v4.4.45',
            'wallabag symfony/mailer v4.4.49',
            'wallabag symfony/routing v4.4.44',
            'wallabag symfony/validator v4.4.48',
        ]];
        yield 'high' => [Priority013::HIGH, [
            'mautic-v4 league/flysystem 1.1.9',
            'mautic-v4 symfony/http-client v4.4.41',
            'mautic-v4 symfony/validator v4.4.41',
            'mautic-v4 symfony/yaml v4.4.37',
            'prestashop-v1 symfony/http-client v4.4.26',
        ]];
    }

    /**
     * @param list<string> $expected
     *
     * @dataProvider thresholds
     */
    #[DataProvider('thresholds')]
    public function testTheFindingsThatReachedAThresholdOnlyByTheAdvisoryRaiseAreNamed(string $threshold, array $expected): void
    {
        $named = [];
        foreach (CorpusFloor::reports() as $report) {
            foreach ($report['findings'] as $recorded) {
                $finding = CorpusFloor::finding($recorded, CorpusFloor::advisories($recorded));
                if (Priority013::rank($finding->priority()) >= Priority013::rank($threshold) && Priority013::rank($finding->grade()) < Priority013::rank($threshold)) {
                    $named[] = $report['name'].' '.$recorded['package'].' '.$recorded['version'];
                }
            }
        }

        self::assertSame($expected, $named);
    }
}
