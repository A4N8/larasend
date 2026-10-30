<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApiSourceRequest;
use App\Models\ApiKey;
use App\Models\Project;
use App\Services\ApiSourceResolver;
use App\Services\InstallationIdentity;
use Illuminate\Http\JsonResponse;

class ConnectionController extends Controller
{
    public function __invoke(ApiSourceRequest $request, ApiSourceResolver $sources, InstallationIdentity $identity): JsonResponse
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get('larasend_api_key');
        /** @var Project $project */
        $project = $request->attributes->get('larasend_project');
        $source = $sources->resolve($request);

        return response()->json(['data' => [
            'object' => 'connection',
            'contract_version' => 2,
            'instance_id' => $identity->id(),
            'project_id' => $project->id,
            'api_key_id' => $key->id,
            'source_id' => $source->id,
            'source_pinned' => $key->source_id !== null || $request->has('source_id'),
            'capabilities' => ['send' => $key->allows('send'), 'read:activity' => $key->allows('read:activity')],
            'idempotency_scope' => 'api_key+source+client_key',
        ]]);
    }
}
