<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Analyzer\RunNote;
use PHPUnit\Framework\Assert;

/**
 * What a document's `note_details` must say about the rest of it, whichever run wrote it: one entry
 * per `notes` string with the same text, the docs URL RunNote gives each known code, the root
 * `network_failures` exactly when an entry sets it, and each count the text prints equal to the one
 * the data carries. A report and an explanation carry `note_details` alike; only a report has the
 * root counts it is held to.
 */
trait AssertsNoteDetails
{
    /** @param array<mixed, mixed> $document a decoded report or explanation */
    private static function assertNoteDetailsAgree(array $document, string $what): void
    {
        Assert::assertArrayHasKey('note_details', $document, $what);
        $details = JsonPath::arrayAt($document, ['note_details']);
        Assert::assertSame($document['notes'], array_column($details, 'text'), $what.': note_details[i].text is notes[i]');
        $isReport = \array_key_exists('network_failures', $document);
        $sets = false;
        $notFromRepository = [];
        foreach ($details as $i => $detail) {
            Assert::assertIsArray($detail, $what);
            $where = $what.' note '.$i;
            $code = JsonPath::stringAt($detail, ['code']);
            $data = JsonPath::arrayAt($detail, ['data']);
            $text = JsonPath::stringAt($detail, ['text']);
            Assert::assertContains($code, RunNote::CODES, $where.': a code lockrot writes');
            Assert::assertSame('https://lockrot.dev/notes/#'.$code, $detail['docs_url'], $where);
            Assert::assertIsBool($detail['sets_network_failures'], $where);
            $sets = $sets || $detail['sets_network_failures'];
            switch ($code) {
                case RunNote::METADATA_UNAVAILABLE:
                    Assert::assertSame(JsonPath::intAt($data, ['package_count']), array_sum(array_column(JsonPath::arrayAt($data, ['reasons']), 'package_count')), $where);
                    Assert::assertTrue($detail['sets_network_failures'], $where);

                    break;
                case RunNote::REPOSITORY_ACTIVITY_RATE_LIMITED:
                case RunNote::REPOSITORY_ACTIVITY_UNREACHABLE:
                    $repositories = JsonPath::arrayAt($data, ['repositories']);
                    Assert::assertSame((string) \count($repositories), self::noteCounts('/ for (\d+) repositories/', $text, $where)[1], $where.': the count the text prints');
                    if ($code === RunNote::REPOSITORY_ACTIVITY_UNREACHABLE) {
                        Assert::assertStringEndsWith(': '.JsonPath::stringAt($repositories, [0, 'message']), $text, $where.': the reason the text prints is the first one');
                    }

                    break;
                case RunNote::REPOSITORY_ACTIVITY_NOT_FOUND:
                    Assert::assertSame((string) \count(JsonPath::arrayAt($data, ['repositories'])), self::noteCounts('/ for (\d+) repositories/', $text, $where)[1], $where);

                    break;
                case RunNote::REPOSITORY_ACTIVITY_ANONYMOUS_CAP:
                    $counts = self::noteCounts('/checked for (\d+) candidate packages, (\d+) packages skipped/', $text, $where);
                    $skipped = JsonPath::intAt($data, ['skipped_no_token']) + JsonPath::intAt($data, ['skipped_budget']);
                    Assert::assertSame([(string) JsonPath::intAt($data, ['checked']), (string) $skipped], [$counts[1], $counts[2] ?? null], $where);

                    break;
                case RunNote::NOT_FROM_COMPOSER_REPOSITORY:
                    $notFromRepository[] = JsonPath::intAt($data, ['package_count']);

                    break;
            }
        }
        if (!$isReport) {
            return;
        }
        Assert::assertSame($document['network_failures'], $sets, $what.': network_failures is any(sets_network_failures)');
        $count = $document['not_from_composer_repository'];
        Assert::assertSame($count === 0 ? [] : [$count], $notFromRepository, $what.': the note counts what the root counts');
    }

    /** @return array<string> the numbers $pattern captures in $text */
    private static function noteCounts(string $pattern, string $text, string $where): array
    {
        if (preg_match($pattern, $text, $matches) !== 1) {
            Assert::fail($where.': "'.$text.'" does not print its count');
        }

        return $matches;
    }
}
