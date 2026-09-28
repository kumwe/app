# ADR 0022 — Trace-context propagation without a tracer or exporter

**Status** Proposed decision record for `GM-OBS-05`; the integrator places and numbers it
**Decided by** Agent under the standing maintainer mandate, 2026-09-24; accepted on maintainer merge
**Findings** `GM-OBS-05` (resolved by decision, not by implementation)
**Gate** B, phase 7 package `P7-D`

## Context

`config/observability.php` declares a `tracing` block (`enabled: false`, `exporter: none`,
`sample_ratio: 0.0`) that nothing in the runtime reads. `RequestIdMiddleware` accepts a well-formed W3C
`traceparent`, stamps its `trace_id` and `span_id` onto every log record the request writes through
`CorrelationContext`, echoes the header back, ignores malformed and all-zero values, and never mints an
identifier. Asynchronous work — queue jobs, outbox and inbox deliveries, the scheduler — carries
`correlation_id` and causation identifiers, not the upstream trace. Earlier prose called this "the part of
distributed tracing that has value", which overstated it: no span is recorded or exported anywhere.

## Decision

Kumwe Version 2 ships **W3C trace-context propagation and no distributed tracing**. Documentation, the
configuration contract and code comments describe propagation and never claim tracing, spans, sampling or
an exporter.

Adopting a tracer and exporter (for example the OpenTelemetry PHP SDK with an OTLP exporter) is a
**separately reviewed dependency and configuration decision**. That review must cover, at minimum: the
supply-chain footprint of the SDK and its transitive dependencies; the performance cost measured with the
`P2-I` harness; redaction of span attributes to the same standard as log fields; propagation across the
asynchronous boundaries listed above; the cardinality of span names; sampling and export failure modes that
never block a request; and the operator configuration surface. Until that decision lands together with the
code that reads it, the `tracing` block stays disabled and is documented as a declaration, not a switch.

## What an operator can do today

- Run a proxy, ingress or upstream service that records traces and emits `traceparent`; Kumwe's JSON log
  lines then join those traces on `trace_id` in any backend that receives both.
- Without an upstream tracer, correlate a request and its asynchronous follow-up work on
  `correlation_id`, which every log line and every durable job, outbox and audit record carries.
- Use the protected `/metrics` endpoint for latency, error and saturation signals.

## Consequences

- `GM-OBS-05` closes by this decision; no exporter, SDK or new configuration key is added.
- `docs/operations/monitoring.md`, `config/observability.php` and `RequestIdMiddleware` state propagation
  only. A later exporter adoption supersedes this record in its own reviewed change.
