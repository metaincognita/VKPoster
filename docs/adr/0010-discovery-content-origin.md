# ADR 0010: Discovery as an independent content origin

Status: accepted for the local Content Discovery Core, 2026-10-06.

## Context

Sources identify user-configured Telegram origins. Radar may discover web/news/social material without any Source, while the existing content revision, selection and text-processing lifecycle should be reused. Fabricating a Telegram Source or injecting fake Telegram messages would compromise that separation; a second text-processing system would duplicate business logic.

## Decision

Keep Discovery entities and metadata separate. Import permitted title/excerpt/URL through DiscoveryMaterialGateway with a durable provenance link to the shared material storage. Only the three required shared source_id columns become nullable. SourceItem uses an explicit non-Telegram origin marker and no source_messages. Registered Discovery material is selected/locked by workspace and import link. Existing ContentProcessor/TextProcessor and text history are reused; approval and revision checks remain mandatory. Source model, reader, publication queue and PostDraft are unaffected.

## Consequences

The historical source_items table name now also covers Discovery-origin text materials. Generic helpers accept an explicit nullable Source only for registered Discovery imports. Source routes cannot access those materials, and Radar routes cannot access Source-only items. UI supports manual selection and text processing for Discovery; image/video origin support and a broader material abstraction may be added later if required. This core does not duplicate generation or publishing pipelines. Imported copies are immutable snapshots of permitted metadata, not protected full articles; retention must preserve the provenance link for existing material history.
