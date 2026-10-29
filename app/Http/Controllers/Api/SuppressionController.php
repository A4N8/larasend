<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SuppressionProviderUnavailableException;
use App\Exceptions\SuppressionRemovalException;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Project;
use App\Services\SuppressionRemovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SuppressionController extends Controller
{
    public function destroy(Request $request, string $suppression, SuppressionRemovalService $removalService): Response|JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('larasend_project');
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('larasend_api_key');

        $suppressionId = filter_var($suppression, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        abort_if($suppressionId === false, 404);

        $record = $project->suppressions()
            ->when($apiKey->source_id !== null, fn ($query) => $query->where('source_id', $apiKey->source_id))
            ->findOrFail($suppressionId);

        try {
            $removalService->remove($record);
        } catch (SuppressionProviderUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        } catch (SuppressionRemovalException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->noContent();
    }
}
