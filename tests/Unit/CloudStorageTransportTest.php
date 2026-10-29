<?php

use Aws\CommandInterface;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Tests\TestCase;

class CloudStorageTransportTestCase extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    public array $managedDisks = [];

    /** @var array<string, array<string, mixed>> */
    public array $diskOverrides = [];

    /** @var array<string, string> */
    public array $environmentOverrides = [];

    /** @var array<string, array<string, mixed>> */
    public array $disksBeforeProviders = [];

    public function createApplication(): Application
    {
        $environment = [
            'LARAVEL_CLOUD' => $this->managedDisks === [] ? null : '1',
            'LARAVEL_CLOUD_DISK_CONFIG' => $this->managedDisks === [] ? null : json_encode($this->managedDisks, JSON_THROW_ON_ERROR),
            ...$this->environmentOverrides,
        ];
        $previousServer = array_intersect_key($_SERVER, $environment);
        $previousEnvironment = array_intersect_key($_ENV, $environment);

        foreach ($environment as $name => $value) {
            unset($_SERVER[$name], $_ENV[$name]);

            if ($value !== null) {
                $_SERVER[$name] = $_ENV[$name] = $value;
            }
        }

        try {
            $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
            $this->traitsUsedByTest = class_uses_recursive(static::class);
            $app->beforeBootstrapping(RegisterProviders::class, function (Application $app): void {
                foreach ($this->diskOverrides as $name => $configuration) {
                    $app['config']->set('filesystems.disks.'.$name, $configuration);
                }

                $this->disksBeforeProviders = $app['config']->get('filesystems.disks');
            });
            $app->make(Kernel::class)->bootstrap();

            return $app;
        } finally {
            foreach (array_keys($environment) as $name) {
                unset($_SERVER[$name], $_ENV[$name]);
            }

            $_SERVER = [...$_SERVER, ...$previousServer];
            $_ENV = [...$_ENV, ...$previousEnvironment];
        }
    }
}

uses(CloudStorageTransportTestCase::class);

/** @return array<int, array<string, mixed>> */
function managedStorageDisks(): array
{
    $connection = ['access_key_id' => 'testing-key', 'access_key_secret' => 'testing-secret',
        'bucket' => 'testing-bucket', 'url' => 'https://cdn.example.test', 'endpoint' => 'https://storage.example.test'];

    return [
        ['disk' => 's3', 'is_default' => true, ...$connection],
        ['disk' => 'archive', ...$connection],
        ['disk' => 'scoped-attachments', 'scoped_disk' => 's3', 'prefix' => 'attachments'],
    ];
}

/** @return array{timeout: float|null, connect_timeout: float|null, attempts: int} */
function resolvedStorageTransport(Application $app, string $disk): array
{
    $attempts = [];
    $app['config']->set('filesystems.disks.'.$disk.'.handler', function (CommandInterface $command) use (&$attempts): PromiseInterface {
        $attempts[] = $command['@http'];

        return Create::rejectionFor(new S3Exception('Simulated retryable failure', $command, ['code' => 'InternalError', 'response' => new Response(500)]));
    });
    $client = $app['filesystem']->disk($disk)->getClient();
    $command = $client->getCommand('HeadBucket', ['Bucket' => 'testing-bucket']);
    $http = $command['@http'];
    expect(fn () => $client->execute($command))->toThrow(S3Exception::class);

    return ['timeout' => $http['timeout'] ?? null, 'connect_timeout' => $http['connect_timeout'] ?? null, 'attempts' => count($attempts)];
}

it('bounds the resolved SDK after the real Cloud bootstrap replaces managed disks', function (): void {
    $this->managedDisks = managedStorageDisks();
    $this->refreshApplication();

    expect($this->disksBeforeProviders['s3'])->not->toHaveKey('http')
        ->and($this->disksBeforeProviders['s3'])->not->toHaveKey('retries')
        ->and(config('filesystems.default'))->toBe('s3');

    foreach (['s3', 'archive'] as $disk) {
        $configuration = config('filesystems.disks.'.$disk);
        expect(array_diff_key($configuration, array_flip(['http', 'retries'])))->toBe($this->disksBeforeProviders[$disk])
            ->and(resolvedStorageTransport($this->app, $disk))->toBe(['timeout' => 30.0, 'connect_timeout' => 5.0, 'attempts' => 2]);
    }

    foreach (['local', 'public', 'scoped-attachments'] as $disk) {
        expect(config('filesystems.disks.'.$disk))->toBe($this->disksBeforeProviders[$disk]);
    }
});

it('preserves explicit S3 overrides and fills only missing transport options', function (): void {
    $this->managedDisks = managedStorageDisks();
    $base = ['driver' => 's3', 'key' => 'testing-key', 'secret' => 'testing-secret', 'region' => 'eu-central-1',
        'bucket' => 'testing-bucket', 'endpoint' => 'https://custom.example.test', 'throw' => true];
    $this->diskOverrides = [
        's3' => [...$base, 'http' => ['timeout' => 45.0, 'connect_timeout' => 0.0, 'verify' => false], 'retries' => 0],
        'partial' => [...$base, 'http' => ['timeout' => 7.0, 'verify' => false]],
    ];
    $this->refreshApplication();

    expect(config('filesystems.disks.s3'))->toBe($this->diskOverrides['s3'])
        ->and(resolvedStorageTransport($this->app, 's3'))->toBe(['timeout' => 45.0, 'connect_timeout' => 0.0, 'attempts' => 1])
        ->and(config('filesystems.disks.partial.http'))->toBe(['timeout' => 7.0, 'verify' => false, 'connect_timeout' => 5.0])
        ->and(resolvedStorageTransport($this->app, 'partial'))->toBe(['timeout' => 7.0, 'connect_timeout' => 5.0, 'attempts' => 2]);
});

it('retains the existing AWS S3 environment knobs after managed disk replacement', function (): void {
    $this->managedDisks = managedStorageDisks();
    $this->environmentOverrides = ['AWS_S3_TIMEOUT' => '12.5', 'AWS_S3_CONNECT_TIMEOUT' => '2.5', 'AWS_S3_RETRIES' => '0'];
    $this->refreshApplication();

    expect(resolvedStorageTransport($this->app, 's3'))->toBe(['timeout' => 12.5, 'connect_timeout' => 2.5, 'attempts' => 1]);
});
