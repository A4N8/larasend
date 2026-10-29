<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Project;
use App\Models\Source;
use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('keeps chronological message order stable when timestamp ties are updated across message kinds', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Ordering', 'slug' => 'ordering']);
    $workspace->users()->attach($user, ['role' => 'owner']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'Ordering', 'slug' => 'ordering']);
    $source = Source::create(['project_id' => $project->id, 'name' => 'Production', 'environment' => 'prod', 'webhook_token' => 'ordering']);
    $thread = Thread::create(['public_id' => 'thread_ordering', 'workspace_id' => $workspace->id, 'project_id' => $project->id,
        'subject' => 'Ordering', 'subject_key' => 'ordering', 'message_count' => 7, 'last_activity_at' => now()]);
    Queue::fake();
    Http::preventStrayRequests();
    $inbound = [];
    $outbound = [];
    $notes = [];

    foreach (['z_first', 'a_second'] as $suffix) {
        $inbound[] = $thread->inboundEmails()->create(['public_id' => 'inbound_'.$suffix, 'workspace_id' => $workspace->id,
            'project_id' => $project->id, 'source_id' => $source->id, 'from_email' => 'sender@example.net',
            'to_email' => 'support@example.com', 'text' => $suffix, 'mime_path' => 'unused.eml', 'received_at' => now()]);
        $outbound[] = $thread->emails()->create(['public_id' => 'outbound_'.$suffix, 'workspace_id' => $workspace->id,
            'project_id' => $project->id, 'source_id' => $source->id, 'from_email' => 'support@example.com', 'subject' => 'Ordering', 'text' => $suffix]);
        $notes[] = $thread->notes()->create(['user_id' => $user->id, 'body' => $suffix]);
    }

    $earlier = $thread->notes()->create(['user_id' => $user->id, 'body' => 'Earlier']);
    $earlier->forceFill(['created_at' => now()->subSecond()])->save();
    $expected = ['note-'.$earlier->id, $inbound[0]->public_id, $inbound[1]->public_id,
        $outbound[0]->public_id, $outbound[1]->public_id, 'note-'.$notes[0]->id, 'note-'.$notes[1]->id];
    $url = "/projects/{$project->slug}/inbox?thread={$thread->public_id}";
    $this->actingAs($user)->withHeader('X-Inertia', 'true')
        ->withHeader('X-Inertia-Version', app(HandleInertiaRequests::class)->version(request()) ?? '');
    $initial = $this->get($url)->assertSuccessful()->json('props.selectedThread.messages');
    expect(array_column($initial, 'id'))->toBe($expected);

    $inbound[0]->forceFill(['text' => 'Updated inbound'])->save();
    $outbound[0]->forceFill(['status' => 'delivered'])->save();
    $notes[0]->forceFill(['body' => 'Updated note'])->save();
    $updated = $this->get($url)->assertSuccessful()->json('props.selectedThread.messages');
    expect(array_column($updated, 'id'))->toBe($expected)
        ->and(collect($updated)->firstWhere('id', $outbound[0]->public_id)['status'])->toBe('delivered');
    Http::assertNothingSent();
});
