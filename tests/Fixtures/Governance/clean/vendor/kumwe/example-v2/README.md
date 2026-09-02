# Kumwe Example

Fictitious Version 2 manifested package used by the governance fixture. Its canonical namespace is
`Kumwe\Example`.

## Responsibility boundary

Describe a subject with a configured prefix through one stable contract. It does not own site settings,
actor context, authorization or persistence.

## Installation

```bash
composer require kumwe/example-v2
```

## Five-minute example

```php
$service = new Kumwe\Example\ExampleService('example');
echo $service->describe('subject'); // example: subject
```

## Dependency injection

Register `Kumwe\Example\ConfigProvider` with the `ConfigAggregator`. It declares one shared factory for
`ExampleService`, aliases `ExampleServiceInterface` to it, and reads `kumwe.example.prefix`.

## Public API

See [`docs/public-api.md`](docs/public-api.md); the machine record is `resources/public-api/v1.json`.
