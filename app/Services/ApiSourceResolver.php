<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Project;
use App\Models\Source;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ApiSourceResolver
{
    public function resolve(Request $request, bool $requirePinnedSource = false): Source
    {
        /** @var Project $project */
        $project = $request->attributes->get('larasend_project');
        /** @var ApiKey $key */
        $key = $request->attributes->get('larasend_api_key');
        $requestedId = $request->has('source_id') ? $request->integer('source_id') : null;

        if ($key->source_id !== null) {
            abort_if($requestedId !== null && $requestedId !== $key->source_id, 404);

            return $project->sources()->whereKey($key->source_id)->firstOrFail();
        }

        if ($requestedId !== null) {
            return $project->sources()->whereKey($requestedId)->firstOrFail();
        }

        if ($requirePinnedSource) {
            throw ValidationException::withMessages(['source_id' => 'An explicit source_id is required for read-only lookup with an unbound API key.']);
        }

        return $project->sources()->where('environment', $project->default_environment)->firstOrFail();
    }
}
