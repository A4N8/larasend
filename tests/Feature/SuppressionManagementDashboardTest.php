<?php

use App\Exceptions\SuppressionRemovalException;
use App\Models\ApiKey;
use App\Models\Suppression;
use App\Models\User;
use App\Services\SuppressionRemovalService;
use App\Support\ProjectContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function suppressionDashboardFixture(): array
{
    $user = User::factory()->create();
    $project = app(ProjectContext::class)->projectFor($user);
    $source = $project->sources()->sole();
    $suppression = $project->suppressions()->create([
        'workspace_id' => $project->workspace_id, 'source_id' => $source->id,
        'email' => 'person@example.com', 'reason' => 'hard_bounce', 'event_type' => 'Bounce',
    ]);

    return [$user, $project, $suppression];
}

it('searches suppressions and distinguishes active records from retained history in stats and rows', function () {
    [$user, $project, $active] = suppressionDashboardFixture();
    $expired = $active->replicate()->fill(['email' => 'expired@example.com', 'reason' => 'complaint', 'expires_at' => now()->subSecond()]);
    $expired->save();

    $this->actingAs($user)->get("/projects/{$project->slug}/suppressions?q=EXPIRED")
        ->assertInertia(fn ($page) => $page->has('suppressions', 1)
            ->where('suppressions.0.email', 'expired@example.com')->where('suppressions.0.active', false)
            ->where('suppressionStats.active', 1)->where('suppressionStats.expired', 1)
            ->where('sidebarCounts.suppressions', 1)->where('workspace.can_manage_suppressions', true));
});

it('paginates suppression history without truncating it to the first hundred', function () {
    [$user, $project, $first] = suppressionDashboardFixture();
    foreach (range(2, 102) as $number) {
        $row = $first->replicate()->fill(['email' => "person{$number}@example.com"]);
        $row->save();
    }

    $this->actingAs($user)->get("/projects/{$project->slug}/suppressions?page=2")
        ->assertInertia(fn ($page) => $page->has('suppressions', 2)
            ->where('suppressionPagination.page', 2)->where('suppressionPagination.has_previous', true));
});

it('exports only searched suppression records in a streamed formula-safe csv', function () {
    [$user, $project, $first] = suppressionDashboardFixture();
    $formula = $first->replicate()->fill(['email' => '+match@example.com', 'reason' => '=FORMULA()', 'expires_at' => now()->subDay()]);
    $formula->save();

    $response = $this->actingAs($user)->get("/projects/{$project->slug}/activity/export?section=suppressions&q=match&range=1h")
        ->assertDownload('larasend-suppressions.csv');
    $csv = $response->streamedContent();
    expect($csv)->toContain('Recipient,Reason,Source,')->toContain("'+match@example.com")->toContain("'=FORMULA()")
        ->toContain('Expired')->not->toContain('person@example.com');
});

it('allows capable members to remove through the provider-aware service', function () {
    [$owner, $project, $suppression] = suppressionDashboardFixture();
    $member = User::factory()->create();
    $project->workspace->users()->attach($member, ['role' => 'member']);
    $service = Mockery::mock(SuppressionRemovalService::class);
    $service->shouldReceive('remove')->once()->andReturnUsing(fn (Suppression $record) => $record->delete());
    app()->instance(SuppressionRemovalService::class, $service);

    $this->actingAs($member)->delete("/projects/{$project->slug}/suppressions/{$suppression->id}")->assertRedirect("/projects/{$project->slug}/suppressions");
    expect($suppression->fresh())->toBeNull();
});

it('keeps the record and exposes a useful error when provider removal fails', function () {
    [$user, $project, $suppression] = suppressionDashboardFixture();
    $service = Mockery::mock(SuppressionRemovalService::class);
    $service->shouldReceive('remove')->once()->andThrow(new SuppressionRemovalException('Still blocked by the provider.'));
    app()->instance(SuppressionRemovalService::class, $service);

    $this->actingAs($user)->delete("/projects/{$project->slug}/suppressions/{$suppression->id}")
        ->assertSessionHasErrors('suppression');
    expect($suppression->fresh())->not->toBeNull();
});

it('forbids limited members from removing suppressions', function (string $role) {
    [$owner, $project, $suppression] = suppressionDashboardFixture();
    $member = User::factory()->create();
    $project->workspace->users()->attach($member, ['role' => $role]);

    $this->actingAs($member)->delete("/projects/{$project->slug}/suppressions/{$suppression->id}")->assertForbidden();
    expect($suppression->fresh())->not->toBeNull();
})->with(['read_only', 'sender', 'api_keys', 'domains']);

it('requires an explicit grant when creating a key with suppression access and preserves empty grants on rotation', function () {
    [$user, $project] = suppressionDashboardFixture();
    $this->actingAs($user)->post("/projects/{$project->slug}/api-keys", ['name' => 'Default'])->assertRedirect()->assertSessionHasNoErrors();
    expect($project->apiKeys()->sole()->scopes)->toBe(['send', 'read:activity']);
    $this->post("/projects/{$project->slug}/api-keys", ['name' => 'Manager', 'scopes' => ['manage:suppressions']])->assertRedirect();
    expect($project->apiKeys()->where('name', 'Manager')->sole()->scopes)->toBe(['manage:suppressions']);
    $empty = ApiKey::issue($project, 'Deny all', null, [])['api_key'];
    $this->post("/projects/{$project->slug}/api-keys/{$empty->id}/rotate")->assertRedirect();
    expect($project->apiKeys()->where('name', 'Deny all')->sole()->scopes)->toBe([]);
});

it('cannot remove suppression records from another project or workspace', function (bool $sameWorkspace) {
    [$user, $project] = suppressionDashboardFixture();
    if ($sameWorkspace) {
        $other = $project->workspace->projects()->create(['name' => 'Other', 'slug' => 'other', 'default_environment' => 'prod']);
    } else {
        $other = app(ProjectContext::class)->projectFor(User::factory()->create());
    }
    $suppression = $other->suppressions()->create([
        'workspace_id' => $other->workspace_id, 'email' => 'private@example.com', 'reason' => 'complaint', 'event_type' => 'Complaint',
    ]);

    $this->actingAs($user)->delete("/projects/{$project->slug}/suppressions/{$suppression->id}")->assertNotFound();
    expect($suppression->fresh())->not->toBeNull();
})->with([true, false]);

it('prevents api-key-only members from granting suppression management while keeping original grants available', function () {
    [$owner, $project] = suppressionDashboardFixture();
    $member = User::factory()->create();
    $project->workspace->users()->attach($member, ['role' => 'api_keys']);

    $this->actingAs($member)->post("/projects/{$project->slug}/api-keys", ['name' => 'Unauthorized manager', 'scopes' => ['manage:suppressions']])->assertForbidden();
    expect($project->apiKeys()->count())->toBe(0);

    $this->post("/projects/{$project->slug}/api-keys", ['name' => 'Original grants', 'scopes' => ['send', 'read:activity']])->assertRedirect()->assertSessionHasNoErrors();
    expect($project->apiKeys()->sole()->scopes)->toBe(['send', 'read:activity']);
    $key = $project->apiKeys()->sole();
    $this->post("/projects/{$project->slug}/api-keys/{$key->id}/rotate")->assertRedirect()->assertSessionHas('newApiKey');
    expect($project->apiKeys()->sole()->scopes)->toBe(['send', 'read:activity']);
});

it('does not expose a managed key to a limited member by rotating it', function () {
    [$owner, $project] = suppressionDashboardFixture();
    $member = User::factory()->create();
    $project->workspace->users()->attach($member, ['role' => 'api_keys']);
    $key = ApiKey::issue($project, 'Managed key', $project->sources()->sole(), ['manage:suppressions'])['api_key'];
    $original = $key->fresh()->getAttributes();

    $this->actingAs($member)->post("/projects/{$project->slug}/api-keys/{$key->id}/rotate")->assertForbidden()->assertSessionMissing('newApiKey');

    expect($key->fresh()->getAttributes())->toBe($original)->and($project->apiKeys()->count())->toBe(1);
});

it('allows a capable member to issue and rotate a management key', function () {
    [$owner, $project] = suppressionDashboardFixture();
    $member = User::factory()->create();
    $project->workspace->users()->attach($member, ['role' => 'member']);

    $this->actingAs($member)->post("/projects/{$project->slug}/api-keys", ['name' => 'Managed key', 'scopes' => ['manage:suppressions']])->assertRedirect()->assertSessionHasNoErrors();
    $key = $project->apiKeys()->sole();
    $this->post("/projects/{$project->slug}/api-keys/{$key->id}/rotate")->assertRedirect()->assertSessionHas('newApiKey');
    expect($key->fresh())->toBeNull()->and($project->apiKeys()->sole()->scopes)->toBe(['manage:suppressions']);
});

it('escapes formula-like text in filtered streamed activity exports', function () {
    [$owner, $project] = suppressionDashboardFixture();
    $template = $project->templates()->create(['name' => 'Formula', 'slug' => '=template', 'subject' => 'Template']);
    $email = $project->emails()->create([
        'public_id' => 'email_formula', 'workspace_id' => $project->workspace_id, 'source_id' => $project->sources()->sole()->id,
        'template_id' => $template->id, 'status' => 'delivered', 'subject' => '=HYPERLINK("https://example.com")',
        'from_email' => 'sender@example.com', 'ses_message_id' => '@formula-id',
    ]);
    $email->recipients()->create(['type' => 'to', 'email' => '+formula@example.com']);
    $excluded = $email->replicate()->fill(['public_id' => 'email_excluded', 'subject' => 'Excluded by status', 'status' => 'bounced']);
    $excluded->save();

    $response = $this->actingAs($owner)->get("/projects/{$project->slug}/activity/export?section=outbound&q=formula&status=delivered")
        ->assertDownload('larasend-activity.csv');
    $csv = $response->streamedContent();
    $rows = array_map(fn (string $line): array => str_getcsv($line), array_filter(explode("\n", trim($csv))));

    expect($rows)->toHaveCount(2)->and($rows[1][2])->toBe("'+formula@example.com")
        ->and($rows[1][3])->toBe("'=HYPERLINK(\"https://example.com\")")
        ->and($rows[1][4])->toBe("'=template")->and($rows[1][6])->toBe("'@formula-id")
        ->and($csv)->not->toContain('email_excluded');
});

it('preserves the dashboard blocker and shows the exact IAM permission needed after an SES denial', function () {
    [$user, $project, $suppression] = suppressionDashboardFixture();
    $suppression->source->update(['aws_access_key_id' => 'test-key', 'aws_secret_access_key' => 'test-secret']);
    Http::fake(['*' => Http::response(['message' => 'Sensitive provider details'], 403)]);

    $this->actingAs($user)->delete("/projects/{$project->slug}/suppressions/{$suppression->id}")
        ->assertSessionHasErrors(['suppression' => 'Amazon SES denied suppression removal. Grant ses:DeleteSuppressedDestination to this source’s IAM principal, then retry. The local blocker was preserved.']);

    expect($suppression->fresh())->not->toBeNull();
});

it('rejects malformed or out-of-range dashboard suppression ids before querying the table', function (string $id, bool $projectRoute) {
    [$owner, $project] = suppressionDashboardFixture();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $path = $projectRoute ? "/projects/{$project->slug}/suppressions/{$id}" : "/suppressions/{$id}";

    $this->actingAs($owner)->deleteJson($path)->assertNotFound();

    expect(collect($queries)->filter(fn (string $query): bool => str_contains($query, 'suppressions')))->toBeEmpty();
})->with(['not-a-number', '018c0d95-7e7a-708a-98f1-2cc8b5bfc12b', '999999999999999999999999999999', '0'])->with([true, false]);
