<?php

use App\Exceptions\SuppressionProviderUnavailableException;
use App\Exceptions\SuppressionRemovalException;
use App\Models\Project;
use App\Models\Source;
use App\Models\Suppression;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SesV2Client;
use App\Services\SuppressionRemovalService;
use Aws\Credentials\Credentials;
use Aws\Signature\SignatureV4;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function removalSuppression(string $provider = 'ses'): Suppression
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Removal', 'slug' => 'removal']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'Removal', 'slug' => 'removal']);
    $source = Source::create(['project_id' => $project->id, 'name' => 'Production', 'provider' => $provider,
        'aws_access_key_id' => 'test-access', 'aws_secret_access_key' => 'test-secret', 'ses_region' => 'eu-central-1',
        'cloudflare_api_token' => 'test-cloudflare', 'cloudflare_account_id' => 'acc-removal', 'webhook_token' => 'removal']);

    return $project->suppressions()->create(['workspace_id' => $workspace->id, 'source_id' => $source->id,
        'email' => 'Remove+tag@example.com', 'reason' => 'complaint', 'event_type' => 'complaint',
        'provider_metadata' => $provider === 'cloudflare' ? ['id' => 'suppression', 'scope' => ['type' => 'account'], 'read_only' => false] : null]);
}

it('removes an SES blocker only after a signed provider request succeeds or confirms absence', function (int $status): void {
    $suppression = removalSuppression();
    DB::table('suppressions')->where('id', $suppression->id)->update(['email' => ' Remove+TAG@EXAMPLE.COM ']);
    Http::fake(function (Request $request) use ($suppression, $status) {
        expect($request->method())->toBe('DELETE')
            ->and($request->url())->toBe('https://email.eu-central-1.amazonaws.com/v2/email/suppression/addresses/remove%2Btag%40example.com')
            ->and($request->hasHeader('Authorization'))->toBeTrue()
            ->and($suppression->fresh())->not->toBeNull();

        return Http::response([], $status);
    });

    app(SuppressionRemovalService::class)->remove($suppression);

    $this->assertModelMissing($suppression);
    Http::assertSentCount(1);
})->with([200, 404]);

it('preserves SES suppression and returns a safe error when provider removal fails', function (): void {
    $suppression = removalSuppression();
    Http::fake(['*' => Http::response(['message' => 'Sensitive upstream details'], 403)]);

    expect(fn () => app(SuppressionRemovalService::class)->remove($suppression))
        ->toThrow(SuppressionProviderUnavailableException::class, 'Grant ses:DeleteSuppressedDestination');
    $this->assertModelExists($suppression);
});

it('preserves a suppression whose provider ownership changed after authorization without provider calls', function (): void {
    $suppression = removalSuppression();
    DB::table('suppressions')->where('id', $suppression->id)->update(['source_id' => null]);
    Http::fake(['*' => Http::response([], 200)]);

    expect(fn () => app(SuppressionRemovalService::class)->remove($suppression))
        ->toThrow(SuppressionRemovalException::class, 'changed');
    $this->assertModelExists($suppression);
    Http::assertNothingSent();
});

it('preserves SES suppression when credentials are unavailable without making a provider request', function (): void {
    $suppression = removalSuppression();
    $suppression->source->update(['aws_access_key_id' => null, 'aws_secret_access_key' => null]);
    config(['services.ses.key' => null, 'services.ses.secret' => null]);
    Http::preventStrayRequests();

    expect(fn () => app(SuppressionRemovalService::class)->remove($suppression))
        ->toThrow(SuppressionProviderUnavailableException::class);
    $this->assertModelExists($suppression);
    Http::assertNothingSent();
});

it('requires a stable complete Cloudflare absence and sends no provider delete request', function (bool $present, bool $readOnly): void {
    $suppression = removalSuppression('cloudflare');
    Http::fake(function (Request $request) use ($present, $readOnly): Response|GuzzleHttp\Promise\PromiseInterface {
        expect($request->method())->toBe('GET');
        $rows = $present ? [['id' => 'suppression', 'email' => 'REMOVE+TAG@EXAMPLE.COM', 'reason' => 'complaint', 'read_only' => $readOnly, 'scope' => ['type' => 'account'], 'expires_at' => null]] : [];

        return Http::response(['success' => true, 'result' => $rows, 'result_info' => ['count' => count($rows), 'next_cursor' => null]]);
    });

    if ($present) {
        expect(fn () => app(SuppressionRemovalService::class)->remove($suppression))
            ->toThrow(SuppressionRemovalException::class, $readOnly ? 'read-only' : 'Cloudflare still lists');
        $this->assertModelExists($suppression);

        return;
    }

    app(SuppressionRemovalService::class)->remove($suppression);
    $this->assertModelMissing($suppression);
})->with([[true, false], [true, true], [false, false]]);

it('preserves Cloudflare rows with unknown or sending-domain scope without pretending account absence verifies removal', function (?array $scope): void {
    $suppression = removalSuppression('cloudflare');
    $suppression->update(['provider_metadata' => $scope === null ? null : ['id' => 'domain', 'scope' => $scope, 'read_only' => false]]);
    Http::preventStrayRequests();

    expect(fn () => app(SuppressionRemovalService::class)->remove($suppression))
        ->toThrow(SuppressionRemovalException::class, 'Provider-scope verification is required');
    $this->assertModelExists($suppression);
    Http::assertNothingSent();
})->with([null, [['type' => 'sending_domain', 'value' => 'mail.example.net']]]);

it('keeps Cloudflare history when verification is unavailable', function (): void {
    $suppression = removalSuppression('cloudflare');
    Http::fake(['*' => Http::response([], 503)]);

    expect(fn () => app(SuppressionRemovalService::class)->remove($suppression))
        ->toThrow(SuppressionProviderUnavailableException::class, 'Cloudflare could not verify');
    $this->assertModelExists($suppression);
});

it('allows explicit removal of a purely local blocker without provider calls', function (): void {
    $suppression = removalSuppression();
    $suppression->update(['source_id' => null]);
    Http::preventStrayRequests();

    app(SuppressionRemovalService::class)->remove($suppression);

    $this->assertModelMissing($suppression);
    Http::assertNothingSent();
});

it('matches AWS SDK signing for encoded SES addresses with session credentials', function (): void {
    $suppression = removalSuppression();
    $source = $suppression->source;
    $source->update(['aws_session_token' => 'test-session']);
    $url = 'https://email.eu-central-1.amazonaws.com/v2/email/suppression/addresses/remove%2Btag%40example.com';
    $expected = (new SignatureV4('ses', 'eu-central-1'))->signRequest(
        new PsrRequest('DELETE', $url, ['Content-Type' => 'application/json']),
        new Credentials('test-access', 'test-secret', 'test-session'),
    );
    $this->travelTo(CarbonImmutable::createFromFormat('Ymd\THis\Z', $expected->getHeaderLine('X-Amz-Date'), 'UTC'));
    Http::fake(function (Request $request) use ($expected) {
        expect($request->header('Authorization')[0])->toBe($expected->getHeaderLine('Authorization'))
            ->and($request->header('X-Amz-Date')[0])->toBe($expected->getHeaderLine('X-Amz-Date'))
            ->and($request->header('X-Amz-Security-Token')[0])->toBe('test-session');

        return Http::response([], 200);
    });

    app(SesV2Client::class)->deleteSuppressedDestination($source, $suppression->email);
    Http::assertSentCount(1);
});
