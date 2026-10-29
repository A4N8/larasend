<?php

use App\Models\Project;
use App\Models\Suppression;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

function canonicalSuppressionProject(string $slug = 'canonical'): Project
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => $slug, 'slug' => $slug]);

    return Project::create(['workspace_id' => $workspace->id, 'name' => $slug, 'slug' => $slug]);
}

function canonicalSuppressionMigration(): Migration
{
    return require database_path('migrations/2026_10_29_124216_add_canonical_suppression_email_unique_index.php');
}

function insertHistoricalSuppression(Project $project, string $email): int
{
    return DB::table('suppressions')->insertGetId([
        'project_id' => $project->id, 'workspace_id' => $project->workspace_id,
        'email' => $email, 'reason' => 'complaint', 'event_type' => 'complaint',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('indexes canonical email without rewriting historical values or timestamps', function (): void {
    $project = canonicalSuppressionProject();
    DB::statement('DROP INDEX suppressions_project_normalized_email_unique');
    $id = insertHistoricalSuppression($project, ' Historical@Example.com ');
    $before = DB::table('suppressions')->where('id', $id)->first();

    canonicalSuppressionMigration()->up();

    expect(DB::table('suppressions')->where('id', $id)->first())->toEqual($before)
        ->and($project->suppressions()->whereNormalizedEmail('historical@example.com')->sole()->id)->toBe($id);
    expect(fn () => insertHistoricalSuppression($project, 'historical@example.com'))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('aborts canonical collisions without modifying or deleting either historical row', function (): void {
    $project = canonicalSuppressionProject();
    DB::statement('DROP INDEX suppressions_project_normalized_email_unique');
    insertHistoricalSuppression($project, ' Duplicate@Example.com ');
    insertHistoricalSuppression($project, 'duplicate@example.com');
    $before = DB::table('suppressions')->orderBy('id')->get();

    expect(fn () => canonicalSuppressionMigration()->up())->toThrow(RuntimeException::class, '1 canonical suppression collision');
    expect(DB::table('suppressions')->orderBy('id')->get())->toEqual($before);
});

it('keeps uniqueness project scoped and folds only ASCII letters and spaces', function (): void {
    $project = canonicalSuppressionProject();
    $other = canonicalSuppressionProject('other');
    insertHistoricalSuppression($project, 'Ä@example.com');
    insertHistoricalSuppression($project, 'ä@example.com');
    insertHistoricalSuppression($other, 'Ä@example.com');

    expect(Suppression::normalizeEmail(' AÄ@EXAMPLE.COM '))->toBe('aÄ@example.com')
        ->and(Suppression::normalizeEmail("\tA@EXAMPLE.COM\t"))->toBe("\ta@example.com\t")
        ->and($project->suppressions()->whereNormalizedEmailIn(['Ä@EXAMPLE.COM'])->count())->toBe(1)
        ->and($project->suppressions()->whereNormalizedEmailNotIn(['Ä@EXAMPLE.COM'])->count())->toBe(1);
});

it('recovers an insert collision through the canonical predicate without duplicating or rewriting the winner', function (): void {
    $project = canonicalSuppressionProject();
    $id = insertHistoricalSuppression($project, ' Winner@EXAMPLE.COM ');
    $winner = Suppression::query()->where('project_id', $project->id)->whereNormalizedEmail('winner@example.com')
        ->createOrFirst([], ['project_id' => $project->id, 'workspace_id' => $project->workspace_id,
            'email' => 'winner@example.com', 'reason' => 'hard_bounce', 'event_type' => 'provider_sync']);

    expect($winner->id)->toBe($id)->and($winner->wasRecentlyCreated)->toBeFalse()
        ->and($winner->email)->toBe(' Winner@EXAMPLE.COM ')
        ->and($project->suppressions()->count())->toBe(1);
});
