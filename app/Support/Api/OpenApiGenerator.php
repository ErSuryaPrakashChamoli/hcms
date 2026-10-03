<?php

namespace App\Support\Api;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;

/**
 * Phase 14: builds the OpenAPI 3.1 description of the public API from the registered routes, so
 * the contract cannot drift from the code (OpenApiTest regenerates it and compares).
 *
 * Every `/api/v1` and `/api/scim/v2` operation is described with:
 * - its method and path, and its required API-key scope (from the `api.key:<scope>` middleware);
 * - its audience and data classification (`x-audience`, `x-data-classification`);
 * - path parameters; pagination and sorting for list endpoints;
 * - the shared response and error envelopes;
 * - curated summaries and request bodies where they exist.
 *
 * Audiences: today every operation is an integration (machine-to-machine) API authenticated with a
 * tenant API key. Employee self-service, manager, HR and administrator user APIs are not offered —
 * people use the PeopleOS application — and are listed as deferred surface in `info.description`.
 */
final class OpenApiGenerator
{
    /** Routes whose list output can be sorted: route name => sortable fields. */
    public const SORTABLE = [
        'api.v1.employees.index' => ['employee_code', 'joining_date', 'updated_at'],
        'api.v1.service-desk.requests' => ['created_at', 'status', 'priority'],
        'api.v1.communications.index' => ['published_at', 'title'],
        'api.v1.engagement.surveys' => ['code', 'name', 'created_at'],
    ];

    /** Curated summaries (anything else is derived from the route name). */
    public const SUMMARIES = [
        'api.v1.integrations.events.store' => 'Post a signed event to an integration (idempotent, processed asynchronously)',
        'api.v1.integrations.events.show' => 'Status of an inbound event by its external event id',
        'api.v1.integrations.references.show' => 'Resolve this integration\'s external id to the PeopleOS record',
        'api.v1.employees.index' => 'List employees (sensitive fields never included in lists)',
        'api.v1.employees.show' => 'One employee (sensitive block only with ?include=sensitive and employees.sensitive.read; audited)',
        'api.v1.employees.store' => 'Hire an employee (Idempotency-Key honoured)',
        'api.v1.pre-employees.store' => 'Recruitment hand-over: create a pre-employee (idempotent on offer.external_reference)',
        'api.v1.leave.requests.store' => 'Submit a leave request on behalf of an employee (Idempotency-Key)',
    ];

    private const SENSITIVE_SCOPES = ['employees.sensitive.read', 'payroll.read', 'compensation.sensitive', 'learning.costs', 'workforce.costs', 'compliance.read'];

    public function __construct(private readonly Router $router) {}

    /** @return array<string, mixed> */
    public function generate(): array
    {
        $paths = [];
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/') && ! str_starts_with($uri, 'api/scim/v2')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $paths['/'.$uri][strtolower($method)] = $this->operation($route, strtolower($method));
            }
        }
        ksort($paths);
        foreach ($paths as &$operations) {
            ksort($operations);
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'PeopleOS API',
                'version' => 'v1',
                'description' => implode("\n\n", [
                    'Tenant-bound integration API of Markedge PeopleOS. Generated from the route table by `php artisan peopleos:openapi`; do not edit by hand.',
                    'Authentication: a tenant API key in `X-Api-Key` (or `Authorization: Bearer`). The key binds the tenant before any lookup. Each operation needs the scope named in `x-scope`. Records of another tenant are 404.',
                    'Conventions (ADR-0014):'
                    .' lists return `{data, meta}` with `page` / `per_page` (≤200);'
                    .' errors return `{message, code, request_id, errors?}`;'
                    .' writes honour an `Idempotency-Key` header (24 h replay);'
                    .' `X-Correlation-Id` / `X-Request-Id` are accepted and echoed;'
                    .' 120 requests per minute per key.',
                    'Audiences: every operation here is an integration (machine-to-machine) API (`x-audience: integration`). Employee self-service, manager, HR and administrator user APIs are NOT part of this contract. People act in the PeopleOS application, where the full security chain (tenant → permission → organisation scope → relationship scope → field security) applies. A user-token API is deferred surface.',
                    'Sensitive data appears only behind the sensitive scopes listed in `x-data-classification: sensitive` operations and is audited.',
                ]),
            ],
            'servers' => [['url' => '/', 'description' => 'This PeopleOS installation']],
            'security' => [['ApiKey' => []]],
            'paths' => $paths,
            'webhooks' => $this->webhooks(),
            'components' => $this->components(),
        ];
    }

    /** @return array<string, mixed> */
    private function operation(Route $route, string $method): array
    {
        $name = (string) $route->getName();
        $scope = $this->scope($route);
        $isScim = str_starts_with($route->uri(), 'api/scim');
        $segments = explode('/', $route->uri());
        $tag = $isScim ? 'scim' : ($segments[2] ?? 'api');
        $parameters = [];
        preg_match_all('/\{(\w+)\??\}/', $route->uri(), $m);
        foreach ($m[1] as $param) {
            $parameters[] = ['name' => $param, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
        }
        $isList = $method === 'get' && ! str_ends_with($route->uri(), '}') && ! $isScim;
        if ($isList) {
            $parameters[] = ['$ref' => '#/components/parameters/Page'];
            $parameters[] = ['$ref' => '#/components/parameters/PerPage'];
        }
        if (isset(self::SORTABLE[$name])) {
            $parameters[] = ['name' => 'sort', 'in' => 'query', 'required' => false, 'description' => 'Comma-separated; prefix - for descending. Allowed: id, '.implode(', ', self::SORTABLE[$name]), 'schema' => ['type' => 'string']];
        }
        $isWrite = in_array($method, ['post', 'put', 'patch', 'delete'], true);
        if ($isWrite && ! $isScim && $name !== 'api.v1.integrations.events.store') {
            $parameters[] = ['$ref' => '#/components/parameters/IdempotencyKey'];
        }
        $parameters[] = ['$ref' => '#/components/parameters/CorrelationId'];

        $operation = [
            'operationId' => $name !== '' ? $name : $method.'_'.Str::slug($route->uri(), '_'),
            'tags' => [$tag],
            'summary' => self::SUMMARIES[$name] ?? $this->summary($name, $method, $route->uri()),
            'x-scope' => $scope,
            'x-audience' => 'integration',
            'x-data-classification' => in_array($scope, self::SENSITIVE_SCOPES, true) ? 'sensitive' : 'internal',
            'parameters' => $parameters,
            'responses' => $this->responses($method, $isList, $name),
        ];
        if ($name === 'api.v1.integrations.events.store') {
            $operation['parameters'][] = ['name' => 'X-PeopleOS-Timestamp', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Unix seconds; must be within the integration\'s window (default ±300 s).'];
            $operation['parameters'][] = ['name' => 'X-PeopleOS-Signature', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'sha256=<hex HMAC-SHA256(secret, "timestamp.body")>'];
            $operation['parameters'][] = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'maxLength' => 191], 'description' => 'Defaults to event_id.'];
            $operation['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/InboundEvent']]]];
        } elseif ($isWrite) {
            $operation['requestBody'] = ['required' => $method !== 'delete', 'content' => [$isScim ? 'application/scim+json' : 'application/json' => ['schema' => ['type' => 'object']]]];
        }

        return $operation;
    }

    /** @return array<string, mixed> */
    private function responses(string $method, bool $isList, string $name): array
    {
        $ok = match (true) {
            $name === 'api.v1.integrations.events.store' => ['202' => ['description' => 'Received (or 200 for a duplicate delivery)', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Record']]]]],
            $method === 'post' => ['200' => ['description' => 'Done, or an idempotent replay', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Record']]]], '201' => ['description' => 'Created']],
            $isList => ['200' => ['description' => 'A page', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Page']]]]],
            default => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Record']]]]],
        };
        $errors = [];
        foreach (['401' => 'Missing, invalid or expired key (or bad signature)', '403' => 'Key lacks the scope, or tenant refused', '404' => 'Unknown record (including another tenant\'s)', '422' => 'Validation or business rule', '429' => 'Rate limited'] as $code => $description) {
            $errors[$code] = ['description' => $description, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
        }

        return $ok + $errors;
    }

    private function scope(Route $route): ?string
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'api.key:')) {
                return substr($middleware, strlen('api.key:'));
            }
        }

        return null;
    }

    private function summary(string $name, string $method, string $uri): string
    {
        $base = $name !== '' ? Str::of($name)->after('api.v1.')->after('scim.')->replace(['.', '-', '_'], ' ')->toString() : $uri;

        return ucfirst(trim(($method === 'get' ? '' : strtoupper($method).' ').$base));
    }

    /** @return array<string, mixed> */
    private function webhooks(): array
    {
        return ['peopleosEvent' => ['post' => [
            'summary' => 'Outbound webhook (signed, retried with backoff, dead-lettered after the maximum attempts)',
            'description' => 'Headers: X-PeopleOS-Event, X-PeopleOS-Delivery (event id; dedupe on it), X-PeopleOS-Timestamp, X-PeopleOS-Signature (sha256=HMAC(secret, "timestamp.body")), X-Correlation-Id. Reject stale timestamps; expect retries.',
            'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/WebhookEnvelope']]]],
            'responses' => ['2XX' => ['description' => 'Accepted by the subscriber']],
        ]]];
    }

    /** @return array<string, mixed> */
    private function components(): array
    {
        return [
            'securitySchemes' => [
                'ApiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key', 'description' => '<prefix>.<secret>, tenant-bound, scoped, expiring'],
                'Bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'The same API key as a bearer token (SCIM clients)'],
            ],
            'parameters' => [
                'Page' => ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1]],
                'PerPage' => ['name' => 'per_page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50]],
                'IdempotencyKey' => ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'maxLength' => 191], 'description' => 'Same key + same request replays the stored response for 24 h; same key + another body is 422; a concurrent duplicate is 409.'],
                'CorrelationId' => ['name' => 'X-Correlation-Id', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9._:-]{8,64}$'], 'description' => 'Echoed back; stored on every audit event the request causes.'],
            ],
            'schemas' => [
                'Error' => ['type' => 'object', 'required' => ['message', 'code', 'request_id'], 'properties' => [
                    'message' => ['type' => 'string'], 'code' => ['type' => 'string', 'examples' => ['not_found', 'forbidden', 'validation_failed', 'idempotency_key_reused', 'invalid_signature']],
                    'request_id' => ['type' => 'string'], 'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ]],
                'PageMeta' => ['type' => 'object', 'properties' => ['page' => ['type' => 'integer'], 'per_page' => ['type' => 'integer'], 'total' => ['type' => 'integer'], 'last_page' => ['type' => 'integer']]],
                'Page' => ['type' => 'object', 'required' => ['data', 'meta'], 'properties' => ['data' => ['type' => 'array', 'items' => ['type' => 'object']], 'meta' => ['$ref' => '#/components/schemas/PageMeta']]],
                'Record' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'object']]],
                'InboundEvent' => ['type' => 'object', 'required' => ['event_id', 'event_type', 'data'], 'properties' => [
                    'event_id' => ['type' => 'string', 'maxLength' => 191], 'event_type' => ['type' => 'string', 'pattern' => '^[a-z0-9_.-]{1,64}$', 'examples' => array_keys(config('peopleos.integration.handlers', []))],
                    'correlation_id' => ['type' => 'string'], 'occurred_at' => ['type' => 'string', 'format' => 'date-time'], 'data' => ['type' => 'object'],
                ]],
                'WebhookEnvelope' => ['type' => 'object', 'required' => ['id', 'event', 'occurred_at', 'data'], 'properties' => [
                    'id' => ['type' => 'string'], 'event' => ['type' => 'string'], 'occurred_at' => ['type' => 'string', 'format' => 'date-time'], 'tenant' => ['type' => ['string', 'null']],
                    'correlation_id' => ['type' => 'string'], 'subject' => ['type' => ['object', 'null']], 'data' => ['type' => 'object'],
                ]],
            ],
        ];
    }
}
