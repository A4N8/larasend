<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SuppressionProviderUnavailableException;
use App\Exceptions\SuppressionRemovalException;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Project;
use App\Models\Suppression;
use App\Services\SuppressionRemovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SuppressionController extends Controller
{
    public function destroy(Request $request, Suppression $suppression, SuppressionRemovalService $removalService): Response|JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('larasend_project');
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('larasend_api_key');

        abort_unless($suppression->project_id === $project->id, 404);
        abort_if($apiKey->source_id !== null && $suppression->source_id !== $apiKey->source_id, 404);

        try {
            $removalService->remove($suppression);
        } catch (SuppressionProviderUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        } catch (SuppressionRemovalException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->noContent();
    }
}
