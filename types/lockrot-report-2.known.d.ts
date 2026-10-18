// GENERATED from lockrot-report-2.schema.json by tools/schema/ts/emit_dts.py: do not edit.
// One literal union per enum (closed) and x-known-values (open: a later release may add a value) node.

/** closed set at #/definitions/envelope/properties/schema */
export type EnvelopeSchema = 2;
/** closed set at #/definitions/level */
export type Level = "info" | "warn" | "high";
/** open set at #/definitions/forgeId */
export type ForgeId = "github" | "gitlab" | "bitbucket" | (string & {});
/** open set at #/definitions/metadataFailureReason */
export type MetadataFailureReason = "offline" | "install_time_budget" | "no_versions" | "fetch_failed" | (string & {});
/** open set at #/definitions/repositoryActivityNotCheckedReason */
export type RepositoryActivityNotCheckedReason = "install_time_budget" | (string & {});
/** open set at #/definitions/originKind */
export type OriginKind = "packagist" | "composer" | "path" | "vcs" | "artifact" | "package" | "unknown" | (string & {});
/** open set at #/definitions/originRegistry/oneOf/0 */
export type OriginRegistry = "packagist.org" | "repo.packagist.com" | "wp-packages.org" | "packages.drupal.org" | (string & {});
/** open set at #/definitions/signalId */
export type SignalId = "S1" | "S2" | "S3" | "S4" | "S5" | "S6" | "S7" | "S8" | "S9" | "S10" | (string & {});
/** open set at #/definitions/advisoriesNotCheckedReason */
export type AdvisoriesNotCheckedReason = "offline" | "composer_too_old" | "install_time_budget" | "lookup_failed" | (string & {});
/** closed set at #/definitions/flag */
export type Flag = "abandoned" | "silent" | "pinned" | "left-behind" | "old-promise" | "stale" | "vulnerable";
/** closed set at #/definitions/maintenanceFlag */
export type MaintenanceFlag = "abandoned" | "silent" | "pinned" | "left-behind" | "old-promise" | "stale";
/** closed set at #/definitions/verdict */
export type Verdict = "critical" | "high" | "medium" | "low" | "unknown" | "finished" | "ok";
/** closed set at #/definitions/grade */
export type Grade = "critical" | "high" | "medium" | "low";
/** closed set at #/definitions/severity */
export type Severity = "critical" | "high" | "medium" | "unrated" | "low";
/** open set at #/definitions/fixKind */
export type FixKind = "update" | "upgrade" | "raise-php" | "unknown" | "blocked" | "none" | (string & {});
/** closed set at #/definitions/factKind */
export type FactKind = "flag" | "advisory";
/** open set at #/definitions/clearedFact/properties/basis */
export type ClearedFactBasis = "branch_releasing" | "released_after_ga" | "outside_range" | "tags_only" | (string & {});
/** open set at #/definitions/unverifiedFact/properties/reason */
export type UnverifiedFactReason = "undated" | "no_release_data" | (string & {});
/** open set at #/definitions/addedFact/properties/basis */
export type AddedFactBasis = "released_before_ga" | "branch_not_releasing" | (string & {});
/** open set at #/definitions/factBaseline/oneOf/0/properties/state */
export type FactBaselineState = "known" | "covered" | "new" | "re_rated" | "fix_lost" | (string & {});
/** closed set at #/definitions/moveCheck/oneOf/0/properties/result */
export type MoveCheckResult = "resolves" | "refused" | "not_checked";
/** closed set at #/definitions/moveCheck/oneOf/0/properties/form/oneOf/0 */
export type MoveCheckForm = "inline" | "with-dependencies" | "with-all-dependencies";
/** open set at #/definitions/moveCheck/oneOf/0/properties/reason/oneOf/0 */
export type MoveCheckReason = "baseline_refused" | "unloaded_name" | "offline" | "install_time_budget" | "not_from_composer_repository" | "no_composer_json" | "composer_json_invalid" | "no_release_read" | "pass_cap" | "solver_error" | (string & {});
/** open set at #/definitions/holder/oneOf/0/properties/next_step/oneOf/0/properties/kind */
export type HolderNextStepKind = "replace" | "find-alternative" | "tag" | "require" | "update" | "raise-php" | "test" | "blocked" | "no-tag" | "no-move" | "no-fix" | "no-single-fix" | (string & {});
/** closed set at #/definitions/heldBy/properties/source */
export type HeldBySource = "root" | "package";
/** closed set at #/definitions/heldBy/properties/link */
export type HeldByLink = "require" | "require-dev" | "conflict" | "replace" | "root-require" | "platform";
/** closed set at #/definitions/phpCheck/properties/raise_size/oneOf/0 */
export type PhpCheckRaiseSize = "major" | "minor" | "patch";
/** closed set at #/definitions/termMaintenance/properties/part */
export type TermMaintenancePart = "maintenance";
/** open set at #/definitions/termMaintenance/properties/role */
export type TermMaintenanceRole = "lead" | "corroborating" | (string & {});
/** open set at #/definitions/termMaintenance/properties/divisor */
export type TermMaintenanceDivisor = 1 | 4 | (number & {});
/** closed set at #/definitions/termSecurity/properties/part */
export type TermSecurityPart = "security";
/** closed set at #/definitions/termSecurity/properties/flag */
export type TermSecurityFlag = "vulnerable";
/** open set at #/definitions/termSecurity/properties/role */
export type TermSecurityRole = "security" | (string & {});
/** open set at #/definitions/termSecurity/properties/multiplier */
export type TermSecurityMultiplier = 1 | 2 | (number & {});
/** open set at #/definitions/modifier/properties/reason */
export type ModifierReason = "transitive" | "unreached" | "dev" | (string & {});
/** open set at #/definitions/modifier/properties/applies_to */
export type ModifierAppliesTo = "maintenance" | "total" | (string & {});
/** open set at #/definitions/modifier/properties/divide_by */
export type ModifierDivideBy = 2 | (number & {});
/** open set at #/definitions/partMaintenance/properties/status */
export type PartMaintenanceStatus = "counted" | "none" | "accepted" | "not_judged" | (string & {});
/** open set at #/definitions/partSecurity/properties/status */
export type PartSecurityStatus = "counted" | "clear" | "unchecked" | (string & {});
/** closed set at #/definitions/withoutRow/properties/remove/properties/kind */
export type WithoutRowRemoveKind = "flag" | "advisory";
/** open set at #/definitions/withoutRow/properties/revealed/items/properties/role */
export type WithoutRowRevealedItemRole = "lead" | "corroborating" | "accepted" | (string & {});
/** open set at #/definitions/scoreGraded/properties/decided_by */
export type ScoreGradedDecidedBy = "maintenance" | "security" | "combination" | "either" | (string & {});
/** closed set at #/definitions/scoreZero/properties/total */
export type ScoreZeroTotal = 0;
/** closed set at #/definitions/scoreZero/properties/exact */
export type ScoreZeroExact = 0;
/** open set at #/definitions/ifApplied/oneOf/0/properties/assumes/items */
export type IfAppliedAssumesItem = "clears_hold" | "composer_resolves" | "no_new_advisories" | (string & {});
/** open set at #/definitions/latest/oneOf/0/properties/relation/oneOf/0 */
export type LatestRelation = "older" | "newer" | "same" | (string & {});
/** open set at #/definitions/replacement/oneOf/0/properties/named_by */
export type ReplacementNamedBy = "repository" | "lock" | (string & {});
/** open set at #/definitions/throughEntry/properties/newest_requires_unmeasured/oneOf/0 */
export type ThroughEntryNewestRequiresUnmeasured = "not_from_composer_repository" | "releases_unknown" | "installed_unlisted" | "through_other_package" | "no_stable_release" | (string & {});
/** open set at #/definitions/move/properties/reason/oneOf/0 */
export type MoveReason = "package_quiet" | "no_higher_release" | "higher_undated" | "releases_unknown" | "affected_range_unknown" | "metadata_not_read" | "local_package" | (string & {});
/** closed set at #/definitions/move/properties/composer_moves_because/items */
export type MoveComposerMovesBecauseItem = "holders" | "crosses_major" | "transitive" | "unreached";
/** open set at #/definitions/ignoredAdvisory/properties/by */
export type IgnoredAdvisoryBy = "policy.advisories" | "audit.ignore" | "audit.ignore-severity" | (string & {});
/** open set at #/definitions/ignoredAdvisory/properties/matched */
export type IgnoredAdvisoryMatched = "id" | "cve" | "remote_id" | "package" | "severity" | (string & {});
/** closed set at #/definitions/securityVulnerable/properties/status */
export type SecurityVulnerableStatus = "vulnerable";
/** open set at #/definitions/securityVulnerable/properties/check */
export type SecurityVulnerableCheck = "complete" | "partial" | (string & {});
/** open set at #/definitions/securityVulnerable/properties/unchecked_reason/oneOf/0 */
export type SecurityVulnerableUncheckedReason = "install_time_budget" | "lookup_failed" | (string & {});
/** closed set at #/definitions/securityVulnerable/properties/move_in/oneOf/0 */
export type SecurityVulnerableMoveIn = "next_step" | "also";
/** closed set at #/definitions/securityVulnerable/properties/partial/oneOf/0/properties/clears_all */
export type SecurityVulnerablePartialClearsAll = false;
/** closed set at #/definitions/securityClear/properties/status */
export type SecurityClearStatus = "clear";
/** closed set at #/definitions/securityClear/properties/check */
export type SecurityClearCheck = "complete";
/** closed set at #/definitions/securityClear/properties/complete */
export type SecurityClearComplete = true;
/** closed set at #/definitions/securityUnchecked/properties/status */
export type SecurityUncheckedStatus = "unchecked";
/** open set at #/definitions/securityUnchecked/properties/check */
export type SecurityUncheckedCheck = "partial" | "not_run" | (string & {});
/** open set at #/definitions/securityUnchecked/properties/unchecked_reason/oneOf/0 */
export type SecurityUncheckedUncheckedReason = "offline" | "composer_too_old" | "install_time_budget" | "lookup_failed" | "not_from_composer_repository" | "unparseable_version" | "no_feed" | (string & {});
/** closed set at #/definitions/headline/properties/unit */
export type HeadlineUnit = "years" | "php" | "reason" | "advisories";
/** closed set at #/definitions/headline/properties/source/oneOf/0 */
export type HeadlineSource = "release" | "push" | "branch";
/** closed set at #/definitions/headline/anyOf/0/properties/unit */
export type HeadlineUnit2 = "years";
/** closed set at #/definitions/headline/anyOf/1/properties/unit */
export type HeadlineUnit3 = "php";
/** closed set at #/definitions/headline/anyOf/2/properties/unit */
export type HeadlineUnit4 = "reason";
/** open set at #/definitions/headline/anyOf/2/properties/value */
export type HeadlineValue = "archived" | "marked" | "branch_snapshot" | "no_stable_release" | (string & {});
/** closed set at #/definitions/headline/anyOf/3/properties/unit */
export type HeadlineUnit5 = "advisories";
/** closed set at #/definitions/degreeAbandoned/properties/reasons/items */
export type DegreeAbandonedReasonsItem = "marked" | "archived";
/** open set at #/definitions/findingFlag/properties/role */
export type FindingFlagRole = "lead" | "corroborating" | "security" | "accepted" | (string & {});
/** closed set at #/definitions/findingFlag/anyOf/0/properties/id */
export type FindingFlagId = "abandoned";
/** closed set at #/definitions/findingFlag/anyOf/0/properties/headline/properties/value */
export type FindingFlagHeadlineValue = "archived" | "marked";
/** closed set at #/definitions/findingFlag/anyOf/1/properties/id */
export type FindingFlagId2 = "silent" | "stale";
/** closed set at #/definitions/findingFlag/anyOf/1/properties/headline/properties/source */
export type FindingFlagHeadlineSource = "release" | "push" | null;
/** closed set at #/definitions/findingFlag/anyOf/2/properties/id */
export type FindingFlagId3 = "pinned";
/** closed set at #/definitions/findingFlag/anyOf/3/properties/id */
export type FindingFlagId4 = "left-behind";
/** closed set at #/definitions/findingFlag/anyOf/3/properties/headline/properties/source */
export type FindingFlagHeadlineSource2 = "branch";
/** closed set at #/definitions/findingFlag/anyOf/4/properties/id */
export type FindingFlagId5 = "old-promise";
/** closed set at #/definitions/findingFlag/anyOf/5/properties/id */
export type FindingFlagId6 = "vulnerable";
/** open set at #/definitions/checkMissing/properties/check */
export type CheckMissingCheck = "repository_activity" | "release_dates" | "advisories" | "releases" | "release_metadata" | (string & {});
/** open set at #/definitions/checkMissing/properties/reason */
export type CheckMissingReason = "no_token" | "anonymous_budget" | "install_time_budget" | "rate_limit" | "fetch_failed" | "offline" | "undated_releases" | "releases_unknown" | "composer_too_old" | "lookup_failed" | "host_not_supported" | "no_feed" | "not_from_composer_repository" | (string & {});
/** open set at #/definitions/checkSkipped/properties/check */
export type CheckSkippedCheck = "repository_activity" | "release_metadata" | "advisories" | "release_branch" | "repository_archived" | (string & {});
/** open set at #/definitions/checkSkipped/properties/reason */
export type CheckSkippedReason = "allowlisted" | "no_repository" | "not_from_composer_repository" | "offline" | "rate_limit" | "fetch_failed" | "anonymous_budget" | "install_time_budget" | "no_token" | "unavailable" | "not_found" | "branch_snapshot" | "host_not_supported" | "repository_not_found" | "unparseable_version" | "accepted_by_config" | (string & {});
/** open set at #/definitions/uncheckedAccepted/properties/check */
export type UncheckedAcceptedCheck = "repository_activity" | "release_metadata" | "advisories" | (string & {});
/** open set at #/definitions/uncheckedAccepted/properties/reason */
export type UncheckedAcceptedReason = "host_not_supported" | "no_feed" | "not_from_composer_repository" | (string & {});
/** closed set at #/definitions/uncheckedAccepted/properties/accepted_by/properties/scope */
export type UncheckedAcceptedAcceptedByScope = "host" | "repository" | "package";
/** closed set at #/definitions/allowlist/oneOf/0/properties/by */
export type AllowlistBy = "builtin" | "project" | "type";
/** open set at #/definitions/allowlist/oneOf/0/properties/reason_id/oneOf/0 */
export type AllowlistReasonId = "php-fig-interfaces" | "php-fig-utilities" | "symfony-polyfills" | "symfony-extension-polyfills" | "symfony-packs" | "getallheaders-polyfill" | "random-compat-empty" | "type-metapackage" | "type-symfony-pack" | (string & {});
/** closed set at #/definitions/allowlist/oneOf/0/properties/reason_by */
export type AllowlistReasonBy = "user" | "lockrot";
/** closed set at #/definitions/metadata/properties/status */
export type MetadataStatus = "read" | "not_from_composer_repository" | "unavailable" | "not_found";
/** closed set at #/definitions/findingBaseline/oneOf/0/properties/status */
export type FindingBaselineStatus = "known" | "new" | "worsened";
/** open set at #/definitions/findingBaseline/oneOf/0/properties/worsened_by/items/properties/why */
export type FindingBaselineWorsenedByItemWhy = "new" | "re_rated" | "fix_lost" | (string & {});
/** open set at #/definitions/gateFact/properties/kind */
export type GateFactKind = "flag" | "advisory" | "signal" | (string & {});
/** open set at #/definitions/findingGate/properties/exempt_by/oneOf/0 */
export type FindingGateExemptBy = "baseline" | (string & {});
/** open set at #/definitions/findingGate/properties/by/items/properties/kind */
export type FindingGateByItemKind = "grade" | "flag" | "vulnerable" | "unchecked" | (string & {});
/** closed set at #/definitions/findingGate/properties/basis/oneOf/0/properties/limited_by */
export type FindingGateBasisLimitedBy = "score" | "cap" | "equal";
/** closed set at #/definitions/s1/properties/marked_by */
export type S1MarkedBy = "repository" | "lock";
/** open set at #/definitions/s3/properties/forge */
export type S3Forge = "github" | "gitlab" | "bitbucket" | (string & {});
/** open set at #/definitions/s6/properties/reason */
export type S6Reason = "branch_snapshot" | "no_stable_release" | (string & {});
/** open set at #/definitions/s8/properties/floor_source/oneOf/0 */
export type S8FloorSource = "project" | "target" | (string & {});
/** open set at #/definitions/s9fix/properties/reason/oneOf/0 */
export type S9fixReason = "releases_unknown" | "affected_range_unknown" | "not_from_composer_repository" | "no_release_outside_range" | (string & {});
/** closed set at #/definitions/s9row/properties/counted */
export type S9rowCounted = true;
/** closed set at #/definitions/signal/anyOf/0/properties/id */
export type SignalId2 = "S1";
/** closed set at #/definitions/signal/anyOf/1/properties/id */
export type SignalId3 = "S2";
/** closed set at #/definitions/signal/anyOf/2/properties/id */
export type SignalId4 = "S3";
/** closed set at #/definitions/signal/anyOf/3/properties/id */
export type SignalId5 = "S4";
/** closed set at #/definitions/signal/anyOf/4/properties/id */
export type SignalId6 = "S5";
/** closed set at #/definitions/signal/anyOf/5/properties/id */
export type SignalId7 = "S6";
/** closed set at #/definitions/signal/anyOf/6/properties/id */
export type SignalId8 = "S7";
/** closed set at #/definitions/signal/anyOf/7/properties/id */
export type SignalId9 = "S8";
/** closed set at #/definitions/signal/anyOf/8/properties/id */
export type SignalId10 = "S9";
/** closed set at #/definitions/signal/anyOf/9/properties/id */
export type SignalId11 = "S10";
/** open set at #/definitions/activityEvent */
export type ActivityEvent = "push" | "commit" | (string & {});
/** open set at #/definitions/activityReason */
export type ActivityReason = "allowlisted" | "not_from_composer_repository" | "no_repository" | "host_not_supported" | "repository_not_found" | "offline" | "install_time_budget" | "no_token" | "anonymous_budget" | "rate_limit" | "fetch_failed" | (string & {});
/** closed set at #/definitions/activity/properties/status */
export type ActivityStatus = "read" | "not_found" | "not_read";
/** closed set at #/definitions/activity/anyOf/0/properties/status */
export type ActivityStatus2 = "read";
/** closed set at #/definitions/activity/anyOf/1/properties/status */
export type ActivityStatus3 = "not_found";
/** closed set at #/definitions/activity/anyOf/1/properties/reason */
export type ActivityReason2 = "repository_not_found";
/** closed set at #/definitions/activity/anyOf/2/properties/status */
export type ActivityStatus4 = "not_read";
/** open set at #/definitions/installedUnmeasured */
export type InstalledUnmeasured = "branch_snapshot" | "shared_commit" | "undated" | (string & {});
/** open set at #/definitions/releaseUnmeasured */
export type ReleaseUnmeasured = "not_from_composer_repository" | "unavailable" | "not_found" | "no_stable_release" | "undated_releases" | (string & {});
/** open set at #/definitions/branchReleaseUnmeasured */
export type BranchReleaseUnmeasured = "not_from_composer_repository" | "unavailable" | "not_found" | "branch_snapshot" | "no_branch_row" | "undated_releases" | "above_listed_releases" | (string & {});
/** open set at #/definitions/pushUnmeasured */
export type PushUnmeasured = "allowlisted" | "not_from_composer_repository" | "no_repository" | "host_not_supported" | "repository_not_found" | "offline" | "install_time_budget" | "no_token" | "anonymous_budget" | "rate_limit" | "fetch_failed" | "undated" | (string & {});
/** open set at #/definitions/libyearsUnmeasured */
export type LibyearsUnmeasured = "not_from_composer_repository" | "unavailable" | "not_found" | "branch_snapshot" | "shared_commit" | "undated" | "no_stable_release" | "undated_releases" | (string & {});
/** open set at #/definitions/depthUnmeasured */
export type DepthUnmeasured = "unreached" | "no_composer_json" | (string & {});
/** closed set at #/definitions/ageLevel/oneOf/0 */
export type AgeLevel = "warn" | "high";
/** open set at #/definitions/abandonedIgnored/oneOf/0/properties/by */
export type AbandonedIgnoredBy = "policy.abandoned" | "audit.ignore-abandoned" | (string & {});
/** closed set at #/definitions/composerJsonSection */
export type ComposerJsonSection = "require" | "require-dev";
/** closed set at #/definitions/advisoryFeed/properties/answer */
export type AdvisoryFeedAnswer = "answered" | "failed" | "not_asked";
/** open set at #/definitions/advisoryFeed/properties/reason/oneOf/0 */
export type AdvisoryFeedReason = "lookup_failed" | "not_from_composer_repository" | (string & {});
/** closed set at #/definitions/advisoryFeed/anyOf/0/properties/answer */
export type AdvisoryFeedAnswer2 = "answered";
/** closed set at #/definitions/advisoryFeed/anyOf/1/properties/answer */
export type AdvisoryFeedAnswer3 = "failed" | "not_asked";
/** closed set at #/definitions/advisoryRepository/properties/outcome */
export type AdvisoryRepositoryOutcome = "answered" | "failed" | "not_asked" | "no_feed";
/** open set at #/definitions/advisoryRepository/properties/reason/oneOf/0 */
export type AdvisoryRepositoryReason = "offline" | "composer_too_old" | "install_time_budget" | "not_from_composer_repository" | "transport" | "invalid_response" | (string & {});
/** closed set at #/definitions/advisoryRepository/anyOf/0/properties/outcome */
export type AdvisoryRepositoryOutcome2 = "answered";
/** closed set at #/definitions/advisoryRepository/anyOf/1/properties/outcome */
export type AdvisoryRepositoryOutcome3 = "no_feed";
/** closed set at #/definitions/advisoryRepository/anyOf/2/properties/outcome */
export type AdvisoryRepositoryOutcome4 = "failed";
/** closed set at #/definitions/advisoryRepository/anyOf/3/properties/outcome */
export type AdvisoryRepositoryOutcome5 = "not_asked";
/** closed set at #/definitions/advisoryLookup/properties/scope */
export type AdvisoryLookupScope = "all" | "composer-repositories";
/** closed set at #/definitions/advisoryLookup/properties/scope_source */
export type AdvisoryLookupScopeSource = "config" | "default";
/** closed set at #/definitions/finding/properties/priority */
export type FindingPriority = "critical" | "high" | "medium" | "low" | "none";
/** closed set at #/definitions/finding/properties/reach */
export type FindingReach = "direct" | "transitive" | "unreached";
/** open set at #/definitions/scoreRule/properties/id */
export type ScoreRuleId = "counted" | "lead-first" | "corroborating-share" | "advisory-points" | "no-reachable-fix-multiplier" | "security-max" | "divide-reach" | "security-exempt-from-reach" | "sum" | "divide-dev" | "floor-once" | "band-floors" | "zero-verdicts" | "sort" | "gate-bounded-new" | "baseline-cover" | (string & {});
/** open set at #/definitions/scoreRule/properties/kind */
export type ScoreRuleKind = "select" | "share" | "lookup" | "multiply" | "max" | "divide" | "exempt" | "combine" | "floor" | "band" | "zero" | "order" | "gate" | "cover" | (string & {});
/** open set at #/definitions/scoreRule/properties/applies_to */
export type ScoreRuleAppliesTo = "flags" | "maintenance" | "security" | "advisory" | "total" | "findings" | "gate" | (string & {});
/** open set at #/definitions/scoreRule/properties/why */
export type ScoreRuleWhy = "accepted-facts-shown-not-counted" | "strongest-fact-in-full" | "correlated-evidence" | "severity-points" | "one-band-up" | "one-number-per-part" | "reach-halves-maintenance" | "exploitability-independent-of-reach" | "parts-add" | "dev-halves-total" | "two-floor-parts-make-next" | "integer-band-edges" | "score-zero-says-why" | "advisory-findings-first" | "new-fact-sets-level" | "recorded-lead-covers-below" | (string & {});
/** open set at #/definitions/scoreRule/properties/order */
export type ScoreRuleOrder = "flag_order" | (string & {});
/** open set at #/definitions/scoreRule/properties/table */
export type ScoreRuleTable = "severities" | "zero_verdicts" | (string & {});
/** open set at #/definitions/scoreRule/properties/of */
export type ScoreRuleOf = "counted" | "lead-first" | "corroborating-share" | "advisory-points" | "no-reachable-fix-multiplier" | "security-max" | "divide-reach" | "security-exempt-from-reach" | "sum" | "divide-dev" | "floor-once" | "band-floors" | "zero-verdicts" | "sort" | "gate-bounded-new" | "baseline-cover" | (string & {});
/** open set at #/definitions/scoreRule/properties/op */
export type ScoreRuleOp = "sum" | (string & {});
/** open set at #/definitions/scoreRule/properties/covers */
export type ScoreRuleCovers = "listed_and_below" | (string & {});
/** open set at #/definitions/scoreRule/properties/accept_group */
export type ScoreRuleAcceptGroup = "liveness" | (string & {});
/** open set at #/definitions/scoreRule/properties/accept_covers */
export type ScoreRuleAcceptCovers = "listed_and_below" | (string & {});
/** closed set at #/definitions/scoreRule/anyOf/0/properties/kind */
export type ScoreRuleKind2 = "select";
/** open set at #/definitions/scoreRule/properties/tie_break/items/properties/key */
export type ScoreRuleTieBreakItemKey = "points" | "severity" | "id" | (string & {});
/** open set at #/definitions/scoreRule/properties/tie_break/items/properties/dir */
export type ScoreRuleTieBreakItemDir = "desc" | "order" | "asc" | (string & {});
/** open set at #/definitions/scoreRule/properties/tie_break/items/properties/collation/oneOf/0 */
export type ScoreRuleTieBreakItemCollation = "bytes" | (string & {});
/** open set at #/definitions/scoreRule/properties/when/items */
export type ScoreRuleWhenItem = "transitive" | "unreached" | "dev" | (string & {});
/** closed set at #/definitions/scoreRule/anyOf/1/properties/kind */
export type ScoreRuleKind3 = "share";
/** closed set at #/definitions/scoreRule/anyOf/2/properties/kind */
export type ScoreRuleKind4 = "lookup";
/** closed set at #/definitions/scoreRule/anyOf/3/properties/kind */
export type ScoreRuleKind5 = "multiply";
/** closed set at #/definitions/scoreRule/anyOf/4/properties/kind */
export type ScoreRuleKind6 = "max";
/** closed set at #/definitions/scoreRule/anyOf/5/properties/kind */
export type ScoreRuleKind7 = "divide";
/** closed set at #/definitions/scoreRule/anyOf/6/properties/kind */
export type ScoreRuleKind8 = "exempt";
/** closed set at #/definitions/scoreRule/anyOf/7/properties/kind */
export type ScoreRuleKind9 = "combine";
/** closed set at #/definitions/scoreRule/anyOf/8/properties/kind */
export type ScoreRuleKind10 = "floor";
/** closed set at #/definitions/scoreRule/anyOf/9/properties/kind */
export type ScoreRuleKind11 = "band";
/** closed set at #/definitions/scoreRule/anyOf/10/properties/kind */
export type ScoreRuleKind12 = "zero";
/** closed set at #/definitions/scoreRule/anyOf/11/properties/kind */
export type ScoreRuleKind13 = "order";
/** closed set at #/definitions/scoreRule/anyOf/12/properties/kind */
export type ScoreRuleKind14 = "gate";
/** closed set at #/definitions/scoreRule/anyOf/13/properties/kind */
export type ScoreRuleKind15 = "cover";
/** closed set at #/definitions/scoreModel/properties/parts/items/properties/id */
export type ScoreModelPartsItemId = "maintenance" | "security";
/** open set at #/definitions/scoreModel/properties/parts/items/properties/combine */
export type ScoreModelPartsItemCombine = "lead_plus_share" | "max" | (string & {});
/** closed set at #/definitions/scoreModel/properties/flags/items/properties/part */
export type ScoreModelFlagsItemPart = "maintenance" | "security";
/** open set at #/definitions/scoreModel/properties/flags/items/properties/thresholds/items */
export type ScoreModelFlagsItemThresholdsItem = "release-warn-years" | "release-high-years" | "push-warn-years" | "push-high-years" | (string & {});
/** open set at #/definitions/scoreModel/properties/flags/items/properties/basis/properties/points */
export type ScoreModelFlagsItemBasisPoints = "band_floor" | "severities" | (string & {});
/** open set at #/definitions/scoreModel/properties/flags/items/properties/points_from/oneOf/0 */
export type ScoreModelFlagsItemPointsFrom = "severities" | (string & {});
/** open set at #/definitions/scoreModel/properties/severities/items/properties/basis/properties/points */
export type ScoreModelSeveritiesItemBasisPoints = "band_floor" | "counts_as" | "share" | (string & {});
/** closed set at #/definitions/scoreModel/properties/zero_verdicts/items/properties/verdict */
export type ScoreModelZeroVerdictsItemVerdict = "finished" | "unknown" | "ok";
/** open set at #/definitions/scoreModel/properties/zero_verdicts/items/properties/when */
export type ScoreModelZeroVerdictsItemWhen = "allowlisted" | "no_metadata" | "otherwise" | (string & {});
/** open set at #/definitions/scoreModel/properties/rounding */
export type ScoreModelRounding = "floor_once" | (string & {});
/** open set at #/definitions/scoreModel/properties/bar_overflow */
export type ScoreModelBarOverflow = "clip" | (string & {});
/** open set at #/definitions/scoreModel/properties/exclusive_groups/items/properties/id */
export type ScoreModelExclusiveGroupsItemId = "liveness" | "branch" | "release_age" | "release_push" | (string & {});
/** open set at #/definitions/scoreModel/properties/exclusive_groups/items/properties/holds_unless/oneOf/0/properties/threshold */
export type ScoreModelExclusiveGroupsItemHoldsUnlessThreshold = "push-warn-years" | "push-high-years" | "release-warn-years" | "release-high-years" | (string & {});
/** open set at #/definitions/scoreModel/properties/exclusive_groups/items/properties/holds_unless/oneOf/0/properties/below */
export type ScoreModelExclusiveGroupsItemHoldsUnlessBelow = "push-warn-years" | "push-high-years" | "release-warn-years" | "release-high-years" | (string & {});
/** open set at #/definitions/scoreModel/properties/sort/items/properties/key */
export type ScoreModelSortItemKey = "verdict" | "security" | "score" | "direct" | "package" | (string & {});
/** open set at #/definitions/scoreModel/properties/sort/items/properties/dir */
export type ScoreModelSortItemDir = "order" | "desc" | "asc" | "true_first" | (string & {});
/** closed set at #/definitions/gateValue/anyOf/0/properties/kind */
export type GateValueKind = "grade" | "flag" | "vulnerable";
/** closed set at #/definitions/gateValue/anyOf/1/properties/kind */
export type GateValueKind2 = "unchecked";
/** closed set at #/definitions/run/properties/target_php_source */
export type RunTargetPhpSource = "option" | "env" | "config" | "platform" | "runtime";
/** closed set at #/definitions/run/properties/fail_on_source */
export type RunFailOnSource = "option" | "env" | "config" | "default";
/** open set at #/definitions/run/properties/mode */
export type RunMode = "check" | "generate_baseline" | (string & {});
/** closed set at #/definitions/run/properties/move_check/oneOf/0/properties/ignores_extensions */
export type RunMoveCheckIgnoresExtensions = true;
/** closed set at #/definitions/run/properties/move_check/oneOf/0/properties/baseline */
export type RunMoveCheckBaseline = "resolves" | "refused" | "not_checked";
/** open set at #/definitions/run/properties/move_check/oneOf/0/properties/reason/oneOf/0 */
export type RunMoveCheckReason = "install_time_budget" | "no_composer_json" | "composer_json_invalid" | "solver_error" | (string & {});
/** open set at #/definitions/noteAdvisoriesDisabledByPolicy/properties/policy_key */
export type NoteAdvisoriesDisabledByPolicyPolicyKey = "policy" | "policy.advisories" | "policy.advisories.audit" | "COMPOSER_POLICY" | (string & {});
/** open set at #/definitions/noteAbandonedNotAuditedByComposer/properties/policy_key */
export type NoteAbandonedNotAuditedByComposerPolicyKey = "policy" | "policy.abandoned" | "policy.abandoned.audit" | "audit.abandoned" | "COMPOSER_AUDIT_ABANDONED" | "COMPOSER_POLICY" | (string & {});
/** open set at #/definitions/noteRepositoryActivityHostNotSupported/properties/hosts/items/properties/listed_in/oneOf/0 */
export type NoteRepositoryActivityHostNotSupportedHostsItemListedIn = "github-domains" | "forgejo-domains" | (string & {});
/** closed set at #/definitions/noteBaselineTargetDiffers/properties/baseline_target_php_source */
export type NoteBaselineTargetDiffersBaselineTargetPhpSource = "option" | "env" | "config" | "platform" | "runtime";
/** closed set at #/definitions/noteBaselineTargetDiffers/properties/run_target_php_source */
export type NoteBaselineTargetDiffersRunTargetPhpSource = "option" | "env" | "config" | "platform" | "runtime";
/** open set at #/definitions/noteMovesNotChecked/properties/reason */
export type NoteMovesNotCheckedReason = "baseline_refused" | (string & {});
/** open set at #/definitions/noteUpdateNowNotChecked/properties/reason */
export type NoteUpdateNowNotCheckedReason = "baseline_refused" | "unloaded_name" | "offline" | "install_time_budget" | "not_from_composer_repository" | "no_composer_json" | "composer_json_invalid" | "no_release_read" | "pass_cap" | "solver_error" | (string & {});
/** open set at #/definitions/noteCode */
export type NoteCode = "offline" | "metadata_unavailable" | "monorepo_parent_unavailable" | "advisory_ignore_unreadable" | "advisories_unavailable" | "advisories_not_checked" | "repository_activity_not_checked" | "repository_activity_anonymous_cap" | "repository_activity_rate_limited" | "repository_activity_unreachable" | "repository_activity_not_found" | "not_from_composer_repository" | "baseline_target_differs" | "baseline_fix_model_differs" | "ignore_does_not_cover_advisories" | "advisories_disabled_by_policy" | "abandoned_markings_ignored" | "abandoned_not_audited_by_composer" | "abandoned_ignore_unreadable" | "repository_activity_host_not_supported" | "moves_not_checked" | "update_now_reduced" | "update_now_refused" | "update_now_not_checked" | "move_check_failed" | "package_name_invalid" | (string & {});
/** closed set at #/definitions/noteDetail/anyOf/0/properties/code */
export type NoteDetailCode = "offline";
/** closed set at #/definitions/noteDetail/anyOf/1/properties/code */
export type NoteDetailCode2 = "metadata_unavailable";
/** closed set at #/definitions/noteDetail/anyOf/2/properties/code */
export type NoteDetailCode3 = "monorepo_parent_unavailable";
/** closed set at #/definitions/noteDetail/anyOf/3/properties/code */
export type NoteDetailCode4 = "advisory_ignore_unreadable";
/** closed set at #/definitions/noteDetail/anyOf/4/properties/code */
export type NoteDetailCode5 = "advisories_unavailable";
/** closed set at #/definitions/noteDetail/anyOf/5/properties/code */
export type NoteDetailCode6 = "advisories_not_checked";
/** closed set at #/definitions/noteDetail/anyOf/6/properties/code */
export type NoteDetailCode7 = "repository_activity_not_checked";
/** closed set at #/definitions/noteDetail/anyOf/7/properties/code */
export type NoteDetailCode8 = "repository_activity_anonymous_cap";
/** closed set at #/definitions/noteDetail/anyOf/8/properties/code */
export type NoteDetailCode9 = "repository_activity_rate_limited";
/** closed set at #/definitions/noteDetail/anyOf/9/properties/code */
export type NoteDetailCode10 = "repository_activity_unreachable";
/** closed set at #/definitions/noteDetail/anyOf/10/properties/code */
export type NoteDetailCode11 = "repository_activity_not_found";
/** closed set at #/definitions/noteDetail/anyOf/11/properties/code */
export type NoteDetailCode12 = "not_from_composer_repository";
/** closed set at #/definitions/noteDetail/anyOf/12/properties/code */
export type NoteDetailCode13 = "baseline_target_differs";
/** closed set at #/definitions/noteDetail/anyOf/13/properties/code */
export type NoteDetailCode14 = "baseline_fix_model_differs";
/** closed set at #/definitions/noteDetail/anyOf/14/properties/code */
export type NoteDetailCode15 = "ignore_does_not_cover_advisories";
/** closed set at #/definitions/noteDetail/anyOf/15/properties/code */
export type NoteDetailCode16 = "advisories_disabled_by_policy";
/** closed set at #/definitions/noteDetail/anyOf/16/properties/code */
export type NoteDetailCode17 = "abandoned_markings_ignored";
/** closed set at #/definitions/noteDetail/anyOf/17/properties/code */
export type NoteDetailCode18 = "abandoned_not_audited_by_composer";
/** closed set at #/definitions/noteDetail/anyOf/18/properties/code */
export type NoteDetailCode19 = "abandoned_ignore_unreadable";
/** closed set at #/definitions/noteDetail/anyOf/19/properties/code */
export type NoteDetailCode20 = "repository_activity_host_not_supported";
/** closed set at #/definitions/noteDetail/anyOf/20/properties/code */
export type NoteDetailCode21 = "moves_not_checked";
/** closed set at #/definitions/noteDetail/anyOf/21/properties/code */
export type NoteDetailCode22 = "update_now_reduced";
/** closed set at #/definitions/noteDetail/anyOf/22/properties/code */
export type NoteDetailCode23 = "update_now_refused";
/** closed set at #/definitions/noteDetail/anyOf/23/properties/code */
export type NoteDetailCode24 = "update_now_not_checked";
/** closed set at #/definitions/noteDetail/anyOf/24/properties/code */
export type NoteDetailCode25 = "move_check_failed";
/** closed set at #/definitions/noteDetail/anyOf/25/properties/code */
export type NoteDetailCode26 = "package_name_invalid";
/** open set at #/definitions/gateNotApplied/properties/check */
export type GateNotAppliedCheck = "advisories" | (string & {});
/** open set at #/definitions/gateNotApplied/properties/reason */
export type GateNotAppliedReason = "offline" | "composer_too_old" | "install_time_budget" | "lookup_failed" | "no_feed" | "not_from_composer_repository" | "unparseable_version" | (string & {});
/** open set at #/definitions/rootGate/properties/tripped_by/items */
export type RootGateTrippedByItem = "strict_network" | "fail_on" | (string & {});
/** open set at #/definitions/rootSecurity/properties/check */
export type RootSecurityCheck = "complete" | "partial" | "not_run" | (string & {});
/** closed set at #/properties/$schema */
export type Schema = "https://lockrot.dev/schema/report-2.json";
