# Content → ordinary post draft (Sources 3.1)

`ContentDraftService` is the export boundary from a current approved SourceItem or registered Discovery material to the existing `PostService::saveDraft()`. `PostDraft` is the existing input value object; the persisted result is an ordinary `Post` in `posts` with status `draft`. There is no SourcePublisher, new publication queue or replacement scheduler.

## Export and immutable provenance

Export checks `sources.manage` / `discovery.manage` and `posts.draft`, scopes all IDs to the workspace, locks the Source (or Discovery workspace) and material, and checks the current content revision and approved decision. The caller selects a completed current text attempt. Plain text is escaped for the existing rich-text editor without changing the input material. Discovery uses its registered import and retains the parent candidate revision.

Selected current image variants for every photo in the logical item/album are copied through `MediaService::upload()`: bounded private reads, SHA-256 verification, existing MIME/sanitization/quota/dedupe pipeline. Originals and processing files remain immutable. A selected current archived video can replace photo attachments, but FakeVideoProvider manifests contain no file and are not attachable. Discovery Core currently exports text only. No outbound source/search/AI calls occur here.

Migration 28 adds `content_post_origins`: workspace, material, post, text attempt, content/selection hashes, idempotency key, immutable JSON snapshot, actor and creation time. The snapshot pins image variants and their processing/settings versions, video generation/settings/basis, resulting library IDs and Discovery revision. Item/post/text deletion leaves a tombstone; workspace deletion cascades. A unique workspace/idempotency key prevents repeated export for the same material revision and selection snapshot. Retries return the existing accessible post without overwriting ordinary editor changes; a deleted post is not silently recreated. New content or selection snapshots require current processing and can create a new draft. Changing processing alone does not replace an already exported draft. Explicit ordinary editor duplication is possible and copies provenance.

Database rollback compensates newly created library objects after failed export and never deletes a deduplicated pre-existing upload. A storage outage during compensation can leave unreachable objects; production garbage collection remains necessary. Existing library quotas and draft constraints still apply.

## Existing publishing preflight

After export, the user opens the ordinary post editor, chooses destination Channels and schedules/publishes through unchanged existing routes and adapters. `ContentOriginGuard` adds a provenance check to `PostService::schedule`, `reschedule`, `retry`, `duplicate`, and the existing `Publisher::execute`. Publisher checks before preparing media and immediately before the external adapter call; stale/missing/rejected materials fail with safe `origin_stale`, never a separate send path. Ordinary posts without provenance retain their behavior. Stored text/image/video attempts must still be completed and match the captured content/selection revision. Selection snapshot changes conservatively invalidate old drafts even if the new decision is also approved. Editing the copied post remains permitted and never edits its source.

No transaction can undo an already started remote social API request. The last preflight narrows that race; stronger concurrent approval/send coordination and production reconciliation remain hardening work. Rollback refuses active `sending` derived publications, cancels queued derived publications and resets scheduled derived posts before dropping provenance; deployments must stop workers during schema rollback.

## UI and operations

Shared material page for Source and Discovery has «Создать черновик», current text-result selection, actual-video selection, and links/statuses for exported drafts. Source image choices are made in existing Image Processing UI. POST routes require CSRF, workspace permissions and a rate limit. Only aggregate counts and `content.draft_created` origin category enter analytics/audit/admin stats, without text or credentials. New jobs/env/provider credentials are not required.

Synthetic end-to-end tests exercise export, editor, scheduler, queue worker, Publisher, actual Telegram/VK/MAX adapters behind MockHttpClient, albums, video archive contract, idempotency, stale/rejected/needs_review guards, deleted origins, rollback/replay, CSRF and tenant/role isolation. Real APIs and live Telegram are intentionally not tested in 3.1. Fake processing providers remain Fake, including the video lifecycle (no actual generation).
