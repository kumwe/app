---
# Example Core Growth Record (schema 4.2, spec section 5.4) for a fictitious App adapter.
# The real records live under docs/architecture/core-growth/KUMWE-CGR-YYYY-NNN.md.
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-001
title: "Prefixed example adapter for the kumwe/example describing contract"
symbols:
  - Kumwe\App\Example\Application\DescribeSubject
layer: application
capability_index_sha256: "37dd08c81e3a071e97f5fb9dac0b2914571c3e391f1ad7b9d594b020dbd06269"
packages_reviewed:
  - package: kumwe/example
    version: 0.1.0
    symbols_inspected:
      - Kumwe\Example\Contract\ExampleServiceInterface
      - Kumwe\Example\ExampleService
    source_inspected:
      - vendor/kumwe/example/src
    tests_inspected:
      - vendor/kumwe/example/tests
search_terms:
  - "describe subject"
  - "prefixed description"
required_capability: "Compose a subject description for the administrator surface through the package contract."
consumers:
  - src/Example/Delivery/Administrator/DescribeHandler.php
overlap_reviewed:
  - Kumwe\Example\ExampleService
decision: approved
decided_by: "Adoption agent"
reviewer: "Platform architecture"
decided_on: "2026-09-02"
pull_request: "https://github.com/kumwe/app/pull/1235"
---

## Capability required

An application service that composes the description of an App subject through the package contract,
applying the site-specific prefix the host configures.

## Why existing package APIs are insufficient

`Kumwe\Example\ExampleService` describes a string; it knows nothing about App subjects, the site
configuration or the authorization decision the administrator surface needs.

## Why extending the owning package is inappropriate

The prefix policy is host authority: it reads site settings and the actor context, which the package
charter excludes.

## Why a new focused package is inappropriate

There is no portable bounded context; the behaviour is a composition of one package contract with
App-owned settings and authorization.

## App-specific responsibility

Orchestration and authority: resolve the actor, authorize the read, compose the description through the
package interface, and hand the result to delivery.

## Tests proving the boundary

`tests/Unit/Example/Application/DescribeSubjectTest.php` proves the service delegates the description
itself to the package interface and never re-implements it.

## Decision

Approved as host orchestration; no package symbol is duplicated.
