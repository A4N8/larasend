<?php

use App\Http\Requests\UpdateSourceRequest;
use App\Models\Source;
use App\Models\User;
use App\Support\ProjectContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

it('defaults new sources to at least two full years', function () {
    $project = app(ProjectContext::class)->projectFor(User::factory()->create());

    expect($project->sources()->firstOrFail()->retention_days)->toBe(731);
});

it('rejects retention below two full years', function (int $days) {
    $validator = Validator::make([
        'name' => 'Production',
        'environment' => 'prod',
        'provider' => 'ses',
        'ses_region' => 'eu-central-1',
        'default_from_email' => 'notifications@example.test',
        'retention_days' => $days,
    ], (new UpdateSourceRequest)->rules());

    expect($validator->errors()->has('retention_days'))->toBeTrue();
})->with([1, 90, 365, 730]);

it('allows unlimited retention and settings of at least two full years', function (int $days) {
    $rules = (new UpdateSourceRequest)->rules();
    $validator = Validator::make(['retention_days' => $days], ['retention_days' => $rules['retention_days']]);

    expect($validator->passes())->toBeTrue();
})->with([0, 731, 1095]);

it('raises existing short retention without shortening longer settings', function () {
    $sources = collect([90, 731, 1095, 0])->map(function (int $days): Source {
        $project = app(ProjectContext::class)->projectFor(User::factory()->create());
        $source = $project->sources()->firstOrFail();
        DB::table('sources')->where('id', $source->id)->update(['retention_days' => $days]);

        return $source;
    });

    $migration = require database_path('migrations/2026_10_29_102353_enforce_two_year_source_retention.php');
    $migration->up();

    expect($sources->map(fn (Source $source): int => $source->fresh()->retention_days)->all())
        ->toBe([731, 731, 1095, 0]);
});

it('defaults raw database inserts to two years too', function () {
    $project = app(ProjectContext::class)->projectFor(User::factory()->create());
    $project->sources()->delete();
    $id = DB::table('sources')->insertGetId([
        'project_id' => $project->id,
        'name' => 'Raw insert',
        'webhook_token' => 'raw-retention-test',
    ]);

    expect(DB::table('sources')->where('id', $id)->value('retention_days'))->toBe(731);
});

it('does not shorten source retention when reseeding demo data', function () {
    $project = app(ProjectContext::class)->projectFor(User::factory()->create());
    $source = $project->sources()->firstOrFail();
    $source->update(['retention_days' => 1095]);
    $domain = $project->domains()->create(['domain' => 'retention.example.test', 'status' => 'verified']);

    $method = new ReflectionMethod(DatabaseSeeder::class, 'seedSource');
    $method->invoke(new DatabaseSeeder, $project, $domain->id);

    expect($source->fresh()->retention_days)->toBe(1095);
});
