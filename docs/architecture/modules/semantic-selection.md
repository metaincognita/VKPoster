# Semantic Selection / Ranking Core

Optional layer of existing `SelectionService`, not a parallel selection or processing pipeline. Source policy is scoped to a Source; Radar policy is scoped to a workspace. All external adapters implement `SemanticSelectionProvider`; Fake remains available for tests/dev; the optional real adapter is configured through the existing provider boundary. No publishing pipeline is introduced.

## Decision composition

`SelectionEngine` still evaluates deterministic rules first, on the complete logical SourceItem including album captions/entities. Without semantic settings, behavior is unchanged: an unconfigured Source needs review, configured deterministic rules choose the outcome, and Radar imports require manual review. Radar candidates/materials use the same engine with non-restrictive defaults after Discovery validation; no additional Radar keyword-rule editor is introduced.

When enabled, semantic evaluation is called only after deterministic `approved`. Deterministic `rejected` or `needs_review` records a blocked evaluation without a provider call. Provider output is strictly typed/validated: decision enum, integer relevance score 0–100, finite confidence 0–1, bounded reason, matched criteria and review flags. Raw provider decision/reason and composed outcome/reason are stored separately.

Automatic approval requires provider `approved`, score at least min_score, confidence at least min_confidence and no review flags. Certain provider rejection rejects. Insufficient score/confidence, provider uncertainty or flags lead to needs_review, or rejected if the user selected the conservative reject mode. Untrusted-instruction flags always require review. Provider failure always requires review, regardless of uncertain mode. Manual approved/rejected remains authoritative for its exact material revision. An edit requires a new decision; previous manual approval does not authorize the new revision.

`source_selection_decisions` remains the final decision read by current Text/Image/Video processing guards. Original source_items/messages are not rewritten. Technical `stored` and processing statuses are independent of selection.

## Storage and revisions

Migration 27 creates:

- `semantic_selection_settings`: workspace, scope_key (`source:<internal ID>` or `radar`), monotonically increasing version, settings snapshot, actor and timestamps. Settings include enabled, bounded natural-language criteria, min_score, min_confidence and uncertain_mode.
- `semantic_selection_evaluations`: workspace/scope/origin (material or discovery), internal origin ID, revision hash, deterministic outcome hash, settings version/snapshot, provider, technical status (pending/processing/completed/blocked/failed), deterministic result, raw semantic decision/score/confidence/reason/annotations, composed decision/reason, safe error and timestamps.

Evaluation identity is workspace + scope + origin + revision + deterministic outcome hash + settings version. Repeated identical evaluation reuses the immutable row. Changed material revision or semantic policy requires a new evaluation. Changed deterministic outcome produces its own row; UI matches that hash, so returning to a prior deterministic rule cannot accidentally display the latest unrelated outcome. Saving settings appends a version and reevaluates automatic decisions while preserving manual overrides. Known retryable provider rejection uses bounded Queue backoff on the same durable attempt. An uncertain paid outcome is not automatically replayed; operator reconciliation or an explicit new policy attempt is required. History is retained; UI shows the latest 30 rows.

Material revision uses the existing `MaterialRepository::revision`, including ordered Telegram message revisions. Discovery candidate revision includes title/excerpt/canonical URL/author metadata/language, not popularity counters. Edits invalidate prior manual overrides and create new revision-specific history. Existing processing results are not deleted; changed selection hashes invalidate their current marker through existing guards.

The origin IDs are polymorphic internal identifiers, not public URLs; domain callers authorize the real material/candidate first and every read/write includes workspace_id. Workspace deletion cascades history. Deleted-origin retention/pruning is future hardening. Migration down removes only the new tables and fails automatic semantic decisions closed to needs_review; manual decisions and original materials are preserved.

## Trust and provider boundary

`SemanticSelectionInput` separates untrusted text/title/metadata, trusted owner criteria, current revision and a fixed policy. Future real adapters must use separate instruction/data roles, strict structured output validation and must never concatenate material into system instructions. Fake executes no material instructions. Its fixture classifier detects common instruction-looking content and flags manual review; this is a demonstration, not proof of prompt-injection immunity for a future LLM.

Fake fixtures demonstrate technology/AI/telescope/battery approval, advertising rejection and ambiguity; category matching must occur in both user criteria and material. Unknown natural-language criteria return needs_review rather than pretend they were understood. Advertising/ambiguity fixtures take precedence over approval fixtures. Fake is refused in production; provider errors become fixed safe messages without exception details, text or secrets. No payload logging is added. Inputs over 100000 characters fail safely instead of approving a truncated fragment.

Ingress/settings evaluation atomically records a pending evaluation and enqueues `SemanticSelectionJob` in the existing durable Queue. Provider HTTP runs after the claim transaction commits, never inside Source ingress. The attempt is persisted before dispatch; only revision identifiers and settings snapshots are durable input, not a duplicate full post payload. Finalization rechecks revision, connection generation, settings and manual decision. Pending results require review until completed.

A crash after dispatch leaves a processing attempt. An overlapping worker backs off; an abandoned attempt fails closed as an uncertain outcome and is never recharged automatically. Known 429/pre-dispatch timeout retries are bounded; paid POST read-timeout/ambiguous failure is protected from replay. Migration 32 binds proven legacy decisions and updates their current processing/Draft hashes; unproven legacy manual decisions require reapproval. Stable selection identity excludes technical timestamps and actors. Deploy migration 32 with workers paused before resuming updated workers.

## Radar relevance

Deterministic Trend Score and stored component explanations remain unchanged. Each candidate receives separate semantic score/confidence/decision. A cluster's UI relevance is the maximum available candidate score, explicitly labeled as such; no claim of a new AI cluster summary. Optional relevance ordering applies within the existing bounded current-status result set (up to 100 clusters), with missing scores last and Trend Score as a tie-breaker. Confidence and needs_review remain visible; high relevance alone is not approval. Global pagination/large-scale ranking belongs to production hardening.

`DiscoveryMaterialGateway` imports through the existing boundary. Semantic policy then evaluates the resulting shared material revision and writes the existing final selection row; when disabled, the previous manual-review behavior is retained. No Source or PostDraft is fabricated. Manual imported-material decisions preserve priority.

## UI, authorization and observability

Source detail and Radar expose separate semantic settings forms. Material detail shows deterministic outcome, current semantic evaluation, score/confidence/reason/criteria/flags, final decision, manual override and history. Radar separately labels semantic relevance and Trend Score. Existing sources.manage/discovery.manage, workspace authorization, consent, CSRF and rate limiting protect settings commands. All text is escaped, form errors retain bounded input, secrets never reach the browser.

Existing audit/analytics record settings changes without free text. Daily semantic outcome metrics and the protected admin stats page expose counts only. Manual correction uses existing material-selection actions with audit; no bypass of workspace roles is added.

## Validation

Unit tests cover all policy decisions, inclusive thresholds, unknown metrics, strict result/settings validation, Fake fixtures and injection-like content. Real MySQL feature tests cover semantic off, deterministic gates, manual priority, edits/albums, evaluation reuse, current-policy display, Discovery revision/ranking/import, tenant isolation, safe failures, migration rollback/replay, metrics and HTTP authorization/CSRF/escaping. Isolated app_test UI fixtures provide Source settings/material, Radar settings/relevance and imported material; no real Telegram/AI or production consent is used.
