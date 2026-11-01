<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

final class CorpusDifferential
{
    /**
     * The fields of a finding that tools/corpus/differential.php compares. Beside them: each flag's FLAG_FIELDS of that
     * script as `flags.<id>.<key>`, and each key of each S9 row's `fix` as `S9.fix.<key>[<advisory id>]`.
     */
    public const FIELDS = [
        'verdict', 'lead', 'direct', 'reach', 'installed_php', 'checks_missing', 'checks_skipped', 'age', 'activity',
        'gate.basis', 'gate.fails', 'gate.reaches_fail_on', 'gate.by', 'gate.exempt_by',
        'score.total', 'score.exact', 'score.band', 'score.decided_by', 'score.rounded_down', 'score.parts', 'score.terms', 'score.modifiers', 'score.without', 'score.accepted',
        'next_step.kind', 'next_step.constraint', 'next_step.version', 'next_step.to_branch', 'next_step.clears', 'next_step.leaves',
        'next_step.adds', 'next_step.clears_all', 'next_step.through', 'next_step.unverified', 'next_step.php_check', 'next_step.commands', 'next_step.crosses_major',
        'next_step.composer_moves_because', 'next_step.if_applied', 'next_step.held_by',
        'security.status', 'security.check', 'security.complete', 'security.unchecked_reason',
        'security.fix_kind', 'security.gets', 'security.partial', 'security.move_in', 'security.installed_branch_fixes',
    ];
}
