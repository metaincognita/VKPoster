# Content real providers (Stage 3.2)

Real adapters extend the existing TextProvider, SemanticSelectionProvider, ImageSearchProvider, ImageEnhancementProvider and VideoProvider interfaces. Core selection, immutable histories, image similarity verification and existing PostService/Publisher are retained. No new publishing pipeline or Discovery polling is introduced.

## Configuration and credentials

All providers default to Fake. Tests use TestEnv and MockHttpClient, never the developer `.env` or the internet. There is no automatic fallback from a failed real provider to fabricated content. Configure only the integrations you intend to use in the local, ignored `.env`:

```dotenv
CONTENT_TEXT_PROVIDER=openai
CONTENT_SEMANTIC_PROVIDER=openai
CONTENT_IMAGE_SEARCH_PROVIDER=tineye
CONTENT_IMAGE_ENHANCEMENT_PROVIDER=replicate
CONTENT_VIDEO_PROVIDER=replicate
OPENAI_API_KEY=
OPENAI_CONTENT_MODEL=gpt-5.4-mini-2026-03-17
TINEYE_API_KEY=
REPLICATE_API_TOKEN=
```

Keys must be obtained using eligible provider accounts and supported regions, then inserted locally, never sent in chat. OpenAI's supported-country list does not include Russia; an API key alone does not establish regional availability. Check eligibility before enabling real calls. Since Stage 3.3, missing credentials do not prevent application startup: execution is denied before HTTP and automation falls back to manual review without substituting Fake content. Merely providing keys does not enable a real provider: explicit selection is also required. `CONTENT_IMAGE_ENHANCEMENT_PROVIDER=disabled` disables enhancement explicitly. Fake remains for development/tests, with the existing production execution guard. Restart long-lived workers after changing env. No real keys were available for Stage 3.2, so paid/live behavior has not been verified. The owner accepted Stage 3.2 on adapters and mocked contracts; OpenAI/TinEye/Replicate live smoke is scheduled for Stage 3.5, with no credentials requested now.

## Text and semantic selection

OpenAI Responses API, no new SDK dependency. A model snapshot is configuration, not business logic. `store=false`; developer policy/criteria/settings and JSON-encoded untrusted material are separate roles. Strict JSON Schema + runtime validation rejects refusals, incomplete output, invalid decision types/ranges and malformed annotations. Separating roles reduces injection exposure but is not proof that an LLM cannot be manipulated: deterministic excludes, thresholds, revision guards and manual decisions remain authoritative. TextProcessor enforces output constraints again after the adapter returns.

Metadata contains allowlisted model and token usage; a labelled integer `estimated_cost_microusd` is calculated only for the documented default model, with cached input accounted for. It is an estimate, not a provider invoice. Prompts, response IDs, raw errors, credentials and signed URLs are not copied into technical metadata/logs. Text networking is outside DB locks. Semantic calls currently retain the existing synchronous Selection transaction; bounded HTTP calls can prolong workspace/source locks. Moving semantic execution to durable asynchronous work and introducing per-workspace cost budgets belongs to production hardening, before large-scale automatic use.

## Image search and enhancement

TinEye API v2 uses `X-API-Key`, a multipart original image, and a bounded list of five size-sorted matches. Candidate bytes come from backlink image URLs; thumbnails are not used as substitutes. Inaccessible, insecure or invalid candidates are skipped. Search match scores never establish identity: existing perceptual/feature verification and quality comparison decide eligibility. Candidate provenance omits signed query strings. Original remains selected until explicit user choice; unverified search candidates cannot be chosen through existing guards.

Real-ESRGAN on Replicate is pinned to version `e1f4a2081605342caf55ba4294914cf266dcdf738397cf8826b48cdae516137c`, scale 2, face restoration off. The open model can later be self-hosted behind the same interface; this release uses managed execution. Original bytes are uploaded through Replicate Files API privately; originals are not resized or overwritten. Result bytes become a distinct archived enhanced variant and still pass local image verification. Enhancement has bounded status checks and `Cancel-After: 30s`; timeout/failure attempts cancellation and preserves the original with an existing warning. Cold starts/large inputs may exceed this synchronous window. A provider outage or lack of search matches never replaces the original with arbitrary content.

## Async video

Replicate Seedance 1 Pro, official-model prediction endpoint. Input: 2–12 seconds, 480p/24fps, requested ratio 16:9/9:16/1:1, existing processed text and optional selected private image. With an image, Seedance inherits that image's ratio, so mismatches are rejected before a paid submission. No music, subtitles, editing or audio pipeline is added.

AsyncVideoProvider is an optional extension; Fake's synchronous lifecycle is unchanged. VideoWorkflow claims pending once, starts outside its transaction, persists `provider_job_id`, and remains processing. Existing Run action also checks status; polling only GETs that same prediction and cannot submit another generation. A 120-second DB poll lease protects concurrent checks and is reclaimable after interruption. Provider execution is remotely bounded to 600 seconds; local attempts expire after 900 seconds. Transient polling failures leave processing and release the lease. Current revision/approval/basis/provider guards are checked before polls and completion. Final output is bounded MP4, probed and archived in private MediaStorage with SHA-256/dimensions/duration metadata, then can be selected/exported through Stage 3.1.

Stage 3.2 introduced manual status checks; Stage 3.3 automation optionally polls the same existing video job through the ordinary Queue. An interruption after remote start but before remote ID persistence cannot be made exactly-once without provider-side idempotency. Such unconfirmed starts are never resubmitted automatically; the user must reconcile the provider dashboard before another paid generation. If local persistence fails after start, the attempt remains reconcilable only remotely. Do not assume a safe local error means no provider charge.

## Transport and media security

Shared ProviderHttp: 5-second connection / 20-second response timeout, at most three attempts. Retry explicit 429 (short Retry-After) and transient GET errors; do not replay ambiguous paid POST timeouts/5xx. Long rate-limit delays return a safe retryable category. No raw body/exception chain in domain errors. Fixed provider endpoints reject redirects so authorization cannot leak to another host. Downloads are unauthenticated direct HTTPS/443 with existing SSRF DNS/IP guard and connection pinning, redirects rejected, MIME checked both in headers and bytes, streaming size/progress limits. Image ceiling 16 MiB/40 million pixels; MP4 ceiling 50 MiB plus video probe. Credentials are never forwarded to media hosts. Candidate servers requiring redirects or HTTP are intentionally rejected.

## Storage and checks

Migration 29 adds nullable `provider_metadata_json` to text processing, semantic evaluation, image variant and video generation histories; video additionally stores `provider_job_id` and `poll_claimed_at`. Existing immutable snapshots and workspace scoping are retained. Rollback removes only these adapter support fields; as with any destructive rollback, coordinate active external jobs before rollback in production.

Mock contract fixtures are synthetic representations of official HTTP schemas, not recorded paid responses. Tests cover trust boundaries, strict outputs, usage persistence, SSRF/MIME/size restrictions, upload/search/enhancement, cancellation/errors, async durable IDs, polling/restart and existing Fake behavior. Live tests require eligible local credentials and have intentionally not been run; they are deferred to Stage 3.5 and are not a Stage 3.2 acceptance blocker.

## Documentation and price snapshot (2026-10-06)

- [OpenAI Responses structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs), [GPT-5.4 mini](https://developers.openai.com/api/docs/models/gpt-5.4-mini), [supported countries](https://developers.openai.com/api/docs/supported-countries). Input $0.75 / million tokens, cached input $0.075, output $4.50: typical 1,000 input + 300 output text tokens ≈ $0.0021; 1,000 + 150 semantic tokens ≈ $0.001425. Actual reasoning/output counts can differ.
- [TinEye official v2 client](https://github.com/TinEye/tineye-api-node), [purchasing searches](https://help.tineye.com/article/275-signing-up): example bundle $200/5,000 ≈ $0.04/search, prepaid bundle required; confirm the account's tariff before purchase.
- [Replicate HTTP API](https://replicate.com/docs/reference/http/), [file inputs](https://replicate.com/docs/topics/predictions/input-files), [official file encoding](https://github.com/replicate/replicate-python/blob/main/replicate/helpers.py).
- [Real-ESRGAN](https://replicate.com/nightmareai/real-esrgan): current displayed billing $0.002/output image.
- [Seedance 1 Pro](https://replicate.com/bytedance/seedance-1-pro): current displayed 480p rate $0.03/output second; default 10 seconds ≈ $0.30. Download/storage/traffic and taxes are excluded. Provider prices can change independently of code.


## Review fixes: safe retries and video fencing

Known HTTP 429 and typed pre-dispatch connect timeouts remain retryable through the existing Queue/backoff. A read timeout or uncertain paid POST outcome is not treated as a safe new paid operation. Text and semantic attempts retain the error classification. Video lease generations fence remote ID writes, poll releases and terminal transitions; a late worker cannot overwrite a completed job. Polling reuses the stored remote job ID. Known rejected video starts may retry the same local job; ambiguous starts remain protected.
