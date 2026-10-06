<?php

declare(strict_types=1);

namespace Lockrot\Json;

/**
 * Where lockrot's JSON documents say their schema lives: a `$schema` URL that names the published
 * copy of the file under resources/. The number in the URL is the document's schema number
 * ({@see \Lockrot\Output\JsonFormatter::SCHEMA}, {@see \Lockrot\Baseline\Baseline::SCHEMA}), and
 * each number ships as its own file ({@see path()}) whose `id` is the same URL. The number and the
 * open sets: docs/schema.md#the-number-and-what-may-change-under-it and docs/schema.md#open-sets.
 * The sets are read strictly by {@see KnownValues}.
 *
 * @internal
 */
final class Schemas
{
    public const BASE_URL = 'https://lockrot.dev/schema/';

    public const REPORT = 'report';
    public const EXPLAIN = 'explain';
    public const BASELINE = 'baseline';
    public const CONFIG = 'config';

    /**
     * The numbers that lockrot ships a schema file for, per document. A number stays once its file
     * has shipped.
     */
    private const NUMBERS = [
        self::REPORT => [1],
        self::EXPLAIN => [1],
        self::BASELINE => [1],
        self::CONFIG => [1],
    ];

    /** @return non-empty-string such as `https://lockrot.dev/schema/report-1.json` */
    public static function url(string $document, int $schema): string
    {
        return self::BASE_URL.$document.'-'.$schema.'.json';
    }


    /** @return non-empty-list<int> oldest first */
    public static function numbers(string $document): array
    {
        if (!isset(self::NUMBERS[$document])) {
            throw new \InvalidArgumentException('lockrot ships no '.$document.' schema');
        }

        return self::NUMBERS[$document];
    }

    /** @return non-empty-string such as `lockrot-report-1.schema.json` */
    public static function fileName(string $document, int $number): string
    {
        return 'lockrot-'.$document.'-'.$number.'.schema.json';
    }

    public static function path(string $document, int $number): string
    {
        if (!\in_array($number, self::numbers($document), true)) {
            throw new \InvalidArgumentException('lockrot ships no '.$document.'-'.$number.' schema');
        }

        return __DIR__.'/../../resources/'.self::fileName($document, $number);
    }
}
