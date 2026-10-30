<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailEventHistoryRequest;
use App\Models\ApiKey;
use App\Models\EmailEvent;
use App\Models\Project;
use App\Services\EmailEventCursor;
use App\Services\EventEvidence;
use App\Services\InstallationIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class EmailEventController extends Controller
{
    public function __invoke(
        EmailEventHistoryRequest $request,
        string $email,
        EmailEventCursor $cursors,
        EventEvidence $evidence,
        InstallationIdentity $identity,
    ): JsonResponse {
        /** @var Project $project */
        $project = $request->attributes->get('larasend_project');
        /** @var ApiKey $key */
        $key = $request->attributes->get('larasend_api_key');
        $model = $project->emails()->where('public_id', $email)
            ->when($key->source_id !== null, fn ($query) => $query->where('source_id', $key->source_id))
            ->firstOrFail(['id', 'public_id', 'project_id', 'source_id', 'status', 'event_sequence']);
        $source = $project->sources()->whereKey($model->source_id)->firstOrFail(['id', 'provider', 'retention_days']);
        $context = [
            'instance_id' => $identity->id(),
            'project_id' => $project->id,
            'api_key_id' => $key->id,
            'source_id' => $model->source_id,
            'email_id' => $model->public_id,
        ];
        $events = $model->events()->where('source_id', $model->source_id);
        $cursor = $request->validated('cursor');
        $state = $cursor !== null ? $cursors->decode($cursor, $context) : [
            'phase' => 'legacy',
            'legacy_after_id' => 0,
            'legacy_upper_id' => (int) (clone $events)->whereNull('email_sequence')->max('id'),
            'after_sequence' => 0,
            'upper_sequence' => (int) $model->event_sequence,
        ];

        if ($state['phase'] === 'checkpoint') {
            $state['phase'] = 'sequence';
            $state['upper_sequence'] = (int) $model->event_sequence;
        }

        $limit = $request->integer('limit', 50);
        $rows = new Collection;
        $hasMore = false;

        if ($state['phase'] === 'legacy') {
            $legacy = (clone $events)->whereNull('email_sequence')
                ->where('id', '>', $state['legacy_after_id'])->where('id', '<=', $state['legacy_upper_id'])
                ->orderBy('id')->limit($limit + 1)->get();
            $rows = $legacy->take($limit);

            if ($legacy->count() > $limit) {
                $state['legacy_after_id'] = (int) $rows->last()->id;
                $hasMore = true;
            } else {
                $state['legacy_after_id'] = $state['legacy_upper_id'];
                $state['phase'] = 'sequence';
            }
        }

        if (! $hasMore) {
            $remaining = $limit - $rows->count();
            $sequenced = (clone $events)->where('email_sequence', '>', $state['after_sequence'])
                ->where('email_sequence', '<=', $state['upper_sequence'])
                ->orderBy('email_sequence')->limit($remaining + 1)->get();
            $included = $sequenced->take($remaining);
            $rows = $rows->concat($included);
            $hasMore = $sequenced->count() > $remaining;

            if ($included->isNotEmpty()) {
                $state['after_sequence'] = (int) $included->last()->email_sequence;
            }

            if (! $hasMore) {
                $state['phase'] = 'checkpoint';
                $state['after_sequence'] = $state['upper_sequence'];
            }
        }

        $nextCursor = $cursors->encode($context, $state);

        return response()->json([
            'data' => $rows->map(function (EmailEvent $event) use ($model, $source, $evidence): array {
                $event->setRelation('email', $model)->setRelation('source', $source);

                return $evidence->for($event);
            })->values(),
            'meta' => [
                'email_snapshot' => ['id' => $model->public_id, 'status' => $model->status, 'source_id' => $model->source_id],
                'pagination' => ['limit' => $limit, 'has_more' => $hasMore, 'next_cursor' => $hasMore ? $nextCursor : null],
                'reconciliation' => [
                    'checkpoint' => $hasMore ? null : $nextCursor,
                    'through_sequence' => $state['upper_sequence'],
                    'legacy_baseline_complete' => $state['phase'] !== 'legacy',
                    'completeness' => 'stored_events_only',
                    'retention_days' => $source->retention_days,
                    'automatic_deletion' => false,
                    'limitations' => ['historical_overwritten_transitions_unrecoverable', 'unreceived_or_unresolved_provider_events_not_included'],
                ],
            ],
        ]);
    }
}
