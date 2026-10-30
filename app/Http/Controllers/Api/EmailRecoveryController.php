<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailRecoveryRequest;
use App\Models\ApiKey;
use App\Models\Project;
use App\Services\ApiSourceResolver;
use Illuminate\Http\JsonResponse;

class EmailRecoveryController extends Controller
{
    public function __invoke(EmailRecoveryRequest $request, ApiSourceResolver $sources): JsonResponse
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get('larasend_api_key');
        /** @var Project $project */
        $project = $request->attributes->get('larasend_project');
        $source = $sources->resolve($request, requirePinnedSource: true);
        $scope = hash('sha256', json_encode([$key->id, $source->id, $request->validated('client_key')], JSON_THROW_ON_ERROR));
        $email = $project->emails()->where('source_id', $source->id)->where('idempotency_key', $scope)
            ->first(['public_id', 'status', 'source_id', 'created_at', 'sent_at']);

        if ($email === null) {
            return response()->json([
                'data' => null,
                'error' => ['code' => 'not_found_or_unresolved', 'message' => 'No matching authorized submission is currently available.'],
            ], 404);
        }

        return response()->json([
            'data' => [
                'id' => $email->public_id,
                'object' => 'email',
                'status' => $email->status,
                'source_id' => $email->source_id,
                'created_at' => $email->created_at->toIso8601String(),
                'sent_at' => $email->sent_at?->toIso8601String(),
            ],
            'meta' => ['matched_by' => 'idempotency_key', 'read_only' => true],
        ]);
    }
}
