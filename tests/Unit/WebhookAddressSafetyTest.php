<?php

use App\Exceptions\UnsafeWebhookUrlException;
use App\Exceptions\WebhookDnsResolutionException;
use App\Services\WebhookUrlGuard;

it('rejects unsafe literal destinations before resolving dns', function (string $url) {
    $guard = new class extends WebhookUrlGuard
    {
        protected function resolveHost(string $host): array
        {
            throw new LogicException('DNS must not be consulted.');
        }
    };

    expect(fn () => $guard->requestOptions($url))->toThrow(UnsafeWebhookUrlException::class);
})->with([
    'http://localhost/hook', 'http://service.localhost/hook', 'http://127.0.0.1/hook',
    'http://10.0.0.1/hook', 'http://169.254.169.254/hook', 'http://100.64.0.1/hook',
    'http://192.0.2.1/hook', 'http://198.18.0.1/hook', 'http://224.0.0.1/hook',
    'http://[::1]/hook', 'http://[fc00::1]/hook', 'http://[fe80::1]/hook',
    'http://[::ffff:127.0.0.1]/hook', 'http://[64:ff9b::7f00:1]/hook',
    'http://[2002:7f00:1::]/hook', 'http://[ff02::1]/hook',
    'https://user:secret@example.com/hook', 'https://example.com./hook',
    'https://2130706433/hook', 'https://0177.0.0.1/hook', 'https://0x7f000001/hook',
    'https://0x7f.0.0.1/hook', 'https://127.1/hook',
    'https://example.com\\@127.0.0.1/hook', "https://example.com/\nheader", 'ftp://example.com/hook',
]);

it('pins public dns answers and disables proxies while preserving the hostname', function () {
    $guard = new class extends WebhookUrlGuard
    {
        protected function resolveHost(string $host): array
        {
            return ['2606:4700:4700::1111', '1.1.1.1'];
        }
    };

    $options = $guard->requestOptions('https://hooks.example.com:8443/events');

    expect($options['curl'][CURLOPT_RESOLVE])->toBe(['hooks.example.com:8443:1.1.1.1'])
        ->and($options['proxy'])->toBe('');
});

it('rejects a dns answer containing even one nonpublic address', function (array $addresses) {
    $guard = new class($addresses) extends WebhookUrlGuard
    {
        public function __construct(private array $addresses) {}

        protected function resolveHost(string $host): array
        {
            return $this->addresses;
        }
    };

    expect(fn () => $guard->requestOptions('https://hooks.example.com/events'))
        ->toThrow(UnsafeWebhookUrlException::class);
})->with([[['1.1.1.1', '10.0.0.1']], [['1.1.1.1', '100.64.0.1']], [['1.1.1.1', '::ffff:127.0.0.1']]]);

it('allows public ipv4 and ipv6 literals without dns', function (string $url) {
    expect((new WebhookUrlGuard)->isSafe($url))->toBeTrue();
})->with(['https://1.1.1.1/events', 'https://[2606:4700:4700::1111]/events']);

it('treats unresolved dns as retryable', function () {
    $guard = new class extends WebhookUrlGuard
    {
        protected function resolveHost(string $host): array
        {
            return [];
        }
    };

    expect(fn () => $guard->requestOptions('https://hooks.example.com/events'))
        ->toThrow(WebhookDnsResolutionException::class);
});
