# Content Discovery / Trend Radar Core

`Source` remains an explicitly configured user origin. `ContentDiscovery` automatically collects metadata from replaceable providers without consulting the Sources list. All external providers are currently fixture-backed Fakes; no Telegram authorization, commercial search, scraping, full article download or AI is performed. Each Fake is disabled in production.

## Data and identities

Migration 26 adds `discovery_items`, `discovery_clusters`, `discovery_item_keys`, `discovery_runs`, `discovery_imports`. Items persist provider/type, stable source key/name, canonical HTTPS URL, external ID, title, permitted excerpt (at most 2000 characters), optional UTC publication date, discovery timestamp, allowlisted author/authority metadata and engagement counters, language, cluster FK/public topic ID, trend score and status. Canonical URL is sanitized but never fetched by this core. No original protected article body or arbitrary provider payload is accepted. Import links retain origin separately from the material's content.

Dedupe is workspace scoped and serialized under a workspace lock. Unique alias keys use canonical URL (fragment/tracking params stripped, query sorted), provider + source key + external ID, meaningful normalized title + source key + publication day, and source/day scoped normalized title+excerpt fingerprint. Very short text falls back to URL for its fingerprint; short generic titles are not dedupe identities. Independent publishers of a story remain separate items even if titles match. Rediscovery adds provider provenance aliases and optionally updates measured engagement, never creates another item, resets discovery/publication time or reopens ignored/imported statuses. Conflicting aliases fail the bounded batch safely rather than merging unrelated items. Canonical URL equivalence and exact-title heuristics are conservative and can still need future human merge/split tooling.

## Heuristic clustering

Lowercase Unicode title/excerpt tokens, a small stop-word list, at least three shared tokens and Jaccard similarity >0.45 join an existing story within 36 hours. Conflicting numeric tokens prevent merging. The first story's token anchor and summary are retained; summary is a provider excerpt, not an AI summary. If publication time is unknown, discovery time is only the clustering fallback and the publication date stays NULL. The latest 200 candidate clusters in that time window are compared. No cross-workspace clustering occurs. Language variants, paraphrases, syndication attribution and topical ambiguities are not solved semantically; this is an explainable heuristic, not proof that two reports concern the same event.

## Trend score (0–100)

Fixed weights, no rescaling to hide missing data:

| Component | Maximum | Formula / evidence |
|---|---:|---|
| Freshness | 25 | 25 × max(0, 1 − age/72h), using latest known publication date |
| Independent sources | 20 | min(20, 5 × (distinct configured source keys − 1)) |
| Discovery velocity | 15 | min(15, 3 × distinct source keys first discovered within 6h) |
| Engagement | 15 | max of min(15, 15 × log(1 + views + 5×reactions + 10×shares) / log(10001)) |
| Authority | 15 | 15 × mean of provided authority ratings per source key |
| Relative growth | 10 | max of clamp(5 × (views/baseline_views − 1), 0, 10), only with positive baseline, explicit comparable flag and same measurement window |

Rounded sum produces the score. Missing publication, engagement, authority or comparable baseline produces NULL, with known weight coverage stored alongside each value/reason. Missing individual reaction/share counters do not become fabricated observations; known counters contribute independently. Authority is a configured/provider rating, not credibility verification. Independent source keys require proper publisher/syndication mappings in real adapters. Discovery velocity measures collection arrivals, not unobserved publication velocity. Rediscoveries do not boost it. All stored stories are rescored on refresh; a story's score is copied onto its items. Production-scale partitioned rescoring/retention is future hardening.

## Content boundary

`DiscoveryMaterialGateway` imports title + permitted excerpt + canonical URL, once, into shared `source_items` with source_id=NULL and a separate `discovery_imports` provenance link. `peer_id=discovery` is an explicit non-Telegram origin marker; no Telegram message IDs, reader event or synthetic Source is created. By default the shared selection row starts needs_review; optional semantic policy can choose an automatic decision. Separate manual approve/reject actions retain priority and allow the existing `ContentProcessor` / `TextProcessor` to process only the current approved revision, keeping original and result history. `MaterialRepository` and ContentProcessor explicitly support registered Discovery materials without a Source; lookup/locking and SQL null comparisons remain scoped. Only the three necessary shared source_id columns become nullable; no Source model, publishing or reader changes are needed. Images/video bases for Discovery imports are not supplied by this core; no image search or generation is invoked.

The current UI exposes collection through a refresh command; domain refresh is callable by a future independent worker/tick. There is no continuously running production radar scheduler or automatic acceptance/import. Durable provider runs record safe completed/failed outcomes; one failed provider does not prevent the others from succeeding. Calls occur outside locks; each bounded batch commits atomically. Production polling, quotas, leases, retries and per-workspace topics belong to future provider integration, without using the publication Queue.

## UI and security

Workspace `/w/{ws}/radar` navigation, status filters, cluster cards with source count, summary, explainable score/unknown metrics, source links, import/ignore and run history. Imported materials open `/radar/materials/{id}` in the existing text-processing view, with separate manual selection controls. `discovery.view/manage` permits owners/admins; all mutation commands use CSRF, expensive calls use rate limits, public IDs are ULIDs and all repository access is workspace scoped. Source content links and user text are escaped; no provider exception content is logged. Audit records only technical actions/counts; refresh/import analytics and daily discovery run metrics use existing hooks.

## Validation

Unit tests cover canonical IDs, unsafe URLs and metadata, clustering bounds/numbers, known/missing score metrics and Fake adapters. Real-MySQL feature tests cover repeated discovery, story aggregation, ignored persistence, safe provider failure, idempotent import into current content processing, manual approval, auth/permissions/CSRF/IDOR, escaping and rollback/replay retaining existing Source data. UI fixtures use app_test only. No external API or paid content is called.

## Optional semantic relevance (2.6)

[Semantic Selection](semantic-selection.md) extends existing Selection for Radar candidates/imported materials. Relevance/confidence/decision are stored independently of Trend Score; non-restrictive deterministic defaults still run through SelectionEngine. Optional relevance ordering is bounded to the current-status cluster list. With semantic off, imports still need manual approval; with it enabled, thresholded automatic selection and existing manual priority apply. No real AI provider is connected.
