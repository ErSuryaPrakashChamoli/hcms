<?php

namespace App\Http\Controllers\Scim;

use App\Domain\Enterprise\Services\Scim;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** SCIM 2.0 /Users (§110). Authenticated by an API key with the `scim` scope (Bearer). */
class ScimUserController extends Controller
{
    public function __construct(private readonly Scim $scim) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->scim->list($request->query('filter'), (int) $request->query('startIndex', 1), (int) $request->query('count', 100));

        return $this->scimJson(['schemas' => [Scim::SCHEMA_LIST], 'totalResults' => $result['total'], 'startIndex' => (int) $request->query('startIndex', 1), 'itemsPerPage' => count($result['resources']), 'Resources' => $result['resources']]);
    }

    public function show(int $id): JsonResponse
    {
        return $this->scimJson($this->scim->resource($this->find($id)));
    }

    public function store(Request $request): JsonResponse
    {
        try {
            return $this->scimJson($this->scim->resource($this->scim->create($request->all())), 201);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function replace(int $id, Request $request): JsonResponse
    {
        return $this->scimJson($this->scim->resource($this->scim->replace($this->find($id), $request->all())));
    }

    public function patch(int $id, Request $request): JsonResponse
    {
        return $this->scimJson($this->scim->resource($this->scim->patch($this->find($id), $request->input('Operations', []))));
    }

    public function destroy(int $id): JsonResponse
    {
        $this->scim->deactivate($this->find($id));

        return response()->json(null, 204);
    }

    public function serviceProviderConfig(): JsonResponse
    {
        return $this->scimJson([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            'patch' => ['supported' => true], 'bulk' => ['supported' => false], 'filter' => ['supported' => true, 'maxResults' => 200], 'changePassword' => ['supported' => false], 'sort' => ['supported' => false], 'etag' => ['supported' => false],
            'authenticationSchemes' => [['type' => 'oauthbearertoken', 'name' => 'API key (Bearer)', 'description' => 'PeopleOS API key with the scim scope']],
        ]);
    }

    public function resourceTypes(): JsonResponse
    {
        return $this->scimJson(['schemas' => [Scim::SCHEMA_LIST], 'totalResults' => 1, 'Resources' => [['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'], 'id' => 'User', 'name' => 'User', 'endpoint' => '/Users', 'schema' => Scim::SCHEMA_USER]]]);
    }

    private function find(int $id): User
    {
        $user = User::forCurrentTenant()->find($id);
        abort_if($user === null, 404, 'User not found');

        return $user;
    }

    private function scimJson(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status)->header('Content-Type', 'application/scim+json');
    }

    private function error(string $detail, int $status): JsonResponse
    {
        return $this->scimJson(['schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'], 'status' => (string) $status, 'detail' => $detail], $status);
    }
}
