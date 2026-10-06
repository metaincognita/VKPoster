# Source Video Generation Core

Video generation is isolated in `Domain/Content/VideoProcessing`, with a replaceable `Integrations/Video/VideoProvider` boundary. It does not use the publication queue, PostDraft, text/image processing services for generation, or Telegram reader. `MaterialRepository` and `ImageRepository` only supply immutable bases.

## Persistence and lifecycle

Migration 25 creates `source_video_generations`. Each attempt has a public ULID, workspace/source/item references, current revision and selection hashes, monotonically increasing settings version, settings and basis JSON snapshots, provider name, pending/processing/completed/failed status, safe error, result manifest, selection flag and UTC timestamps. Original SourceItem and earlier attempts are never overwritten. Text and private image descriptors are copied into the basis snapshot. Provider exceptions and free text are not logged or copied into errors/audit metadata.

Request creates pending work. A separate POST claims pending → processing under a Source lock, calls the provider outside locks and finalizes completed/failed in a short transaction. Repeated execution cannot call the provider twice. Approval, revision, selected image and text bases are checked before execution and final persistence; changes make the attempt failed and discard its result. Historical completed versions remain visible but cannot be selected when their bases are no longer current. Selection is serialized by the same Source lock and clears only this item's previous selection. No other state is changed.

The core deliberately runs its short Fake provider synchronously behind an explicit Run button. It is not a production asynchronous video worker. A process crash during processing requires a new attempt; leases, abandoned-job reconciliation, external job polling/cancellation, private binary storage and orphan cleanup belong to real-provider integration. No public arbitrary output URLs are accepted. VideoResult permits a private archive key or an explicit asset-free demonstration. The Fake is disabled in production and returns demonstration=true when allowed and storage_key=null: completed means the demonstration finished, not that a video exists.

## Settings and bases

Aspect ratio: 9:16, 16:9, 1:1. Duration: 1–60 seconds. Instruction: at most 4000 UTF-8 characters. The text base is original text or an explicitly chosen completed current text attempt. The image base is optional and must be a selected completed current image variant, including a manually selected candidate. Image provenance and verification remain in image processing; video generation does not repeat or bypass it. Only archived identifiers, hash, dimensions, MIME and kind are supplied to the provider, not browser URLs or Telegram credentials.

The snapshots are independent of future settings changes. MySQL JSON object ordering is normalized without loose scalar comparisons. Settings do not name any model/vendor. A real adapter will receive a typed VideoInput and return a typed VideoResult; no real credentials or SDK are currently needed.

## HTTP and UI

`GET /w/{ws}/sources/{source}/items/{item}/videos` displays settings, history, safe errors, basis state and selected version. POST to this path creates a pending job; `/videos/run` runs a job; `/videos/select` selects a completed current version. GET requires sources.view, commands sources.manage, workspace/consent middleware, CSRF and rate limits. All IDs and lookups are workspace/source/item scoped. The material page links to video generation; Fake limitations are explicit and no empty video player is rendered. Audit actions source.video_requested/started/completed/failed/selected contain no prompt or text. Completion/failure analytics and source_video_attempts daily metrics use existing hooks.

## Validation

Unit tests cover settings and provider manifests. Real-MySQL feature tests cover lifecycle, immutable histories and bases, approval/revision races, duplicate execution, safe failures, provider replacement, manual selection, auth/permissions/CSRF/IDOR, UI escaping and migration rollback/replay. UI screenshots/axe run on an isolated app_test fixture, never on live Source data. No real video generation, audio, montage, subtitles or publishing.
