<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support\Contract;

use Lockrot\Data\Forge\RepoRef;

/**
 * The page's words that no PHP grammar prints, kept in lockrot so the renderer reads every words
 * table from the bundle (SPEC-0.14.md §7.9.9). The age words are the explain age block's, and
 * RenderedFromTest renders that block from them.
 */
final class PageWords
{
    public const TABLES = [
        'sort-words.json' => [
            'key' => [
                'verdict' => 'by grade',
                'security' => 'findings with an advisory first, by what its advisories add to the score',
                'score' => 'by the score',
                'direct' => 'direct requirements first',
                'package' => 'by name (bytes)',
            ],
            'dir' => ['order' => 'in the grade order', 'desc' => 'highest first', 'true_first' => 'first', 'asc' => 'A to Z'],
        ],
        'halving-words.json' => [
            'reason' => [
                'transitive' => 'its maintenance counts half: not required directly',
                'unreached' => 'its maintenance counts half: lockrot found no path to it',
                'dev' => 'its whole score counts half: packages-dev',
            ],
            'other' => 'divided by {divide_by}: {reason}',
        ],
        'activity-words.json' => [
            'forge' => RepoRef::LABEL,
            'event' => ['push' => 'push', 'commit' => 'commit'],
        ],
        'age-words.json' => [
            'reading' => ['installed' => 'installed', 'branch_release' => '{branch}', 'release' => 'newest', 'push' => 'last {event}'],
            'not_read' => '{reading} not read ({reason words})',
            'below' => 'below {kind}-warn-years {warn}',
            'past' => 'past {kind}-{level}-years {years}',
            'dated_by' => 'dated by {package}',
        ],
    ];
}
