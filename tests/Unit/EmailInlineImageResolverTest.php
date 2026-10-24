<?php

use App\Models\Email;
use App\Services\EmailInlineImageResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email as MimeEmail;
use Symfony\Component\Mime\Part\DataPart;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    Storage::fake('local');
    Http::preventStrayRequests();
});

it('resolves exact inline raster CIDs from stored MIME without changing the saved HTML', function (): void {
    $this->withoutDeprecationHandling();
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $html = '<!doctype html><html><head><style>.card{color:navy}</style></head><body><p>Здравейте</p><img src="cid:Logo@email.local" width="120"><img src="cid:logo@email.local"><img src="cid:missing@email.local"><img src="https://tracker.test/logo"><img alt="No source"></body></html>';
    $mime = (new MimeEmail)->from('from@example.test')->to('to@example.test')->html($html)
        ->addPart((new DataPart($png, 'logo.png', 'image/png'))->asInline()->setContentId('Logo@email.local'))
        ->attach('pdf-bytes', 'invoice.pdf', 'application/pdf')->toString();
    Storage::disk('local')->put('email.eml', $mime);
    $email = new Email(['html' => $html, 'mime_disk' => 'local', 'mime_path' => 'email.eml']);

    $preview = app(EmailInlineImageResolver::class)->html($email);

    expect($preview)->toContain('src="data:image/png;base64,'.base64_encode($png).'"', 'width="120"', 'Здравейте', '.card{color:navy}', 'src="cid:logo@email.local"', 'src="cid:missing@email.local"', 'https://tracker.test/logo')
        ->and($email->html)->toBe($html);
    Http::assertNothingSent();
});

it('omits ordinary raster attachments and unsafe inline content from CID resolution', function (string $contents, string $mimeType, string $disposition): void {
    $html = '<p>Saved body</p><img src="cid:logo@email.local">';
    $part = (new DataPart($contents, 'logo', $mimeType))->setContentId('logo@email.local');
    if ($disposition === 'inline') {
        $part->asInline();
    }
    $mimeHtml = $disposition === 'attachment' ? '<p>Archived body without inline references</p>' : $html;
    $mime = (new MimeEmail)->from('from@example.test')->to('to@example.test')->html($mimeHtml)->addPart($part)->toString();
    Storage::disk('local')->put('email.eml', $mime);

    expect(app(EmailInlineImageResolver::class)->resolve(new Email(['html' => $html, 'mime_disk' => 'local', 'mime_path' => 'email.eml'])))
        ->toBe(['html' => $html, 'retryable' => false]);
    Http::assertNothingSent();
})->with([
    'ordinary attachment with CID' => [base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='), 'image/png', 'attachment'],
    'forged raster MIME' => ['<svg onload="alert(1)"></svg>', 'image/png', 'inline'],
    'SVG' => ['<svg></svg>', 'image/svg+xml', 'inline'],
    'oversized raster' => [str_pad(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='), 262145, "\0"), 'image/png', 'inline'],
    'excessive dimensions' => [substr_replace(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='), pack('N', 4097), 16, 4), 'image/png', 'inline'],
    'zero dimensions' => [substr_replace(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='), pack('N', 0), 16, 4), 'image/png', 'inline'],
]);

it('resolves a stored inline JPEG using its detected raster MIME type', function (): void {
    $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD//gA+Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBkZWZhdWx0IHF1YWxpdHkK/9sAQwAIBgYHBgUIBwcHCQkICgwUDQwLCwwZEhMPFB0aHx4dGhwcICQuJyAiLCMcHCg3KSwwMTQ0NB8nOT04MjwuMzQy/9sAQwEJCQkMCwwYDQ0YMiEcITIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy/8AAEQgAAQABAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A+f6KKKAP/9k=');
    $html = '<img src="cid:logo@email.local">';
    $mime = (new MimeEmail)->from('from@example.test')->to('to@example.test')->html($html)
        ->addPart((new DataPart($jpeg, 'logo.jpg', 'image/jpeg'))->asInline()->setContentId('logo@email.local'))->toString();
    Storage::disk('local')->put('email.eml', $mime);

    expect(app(EmailInlineImageResolver::class)->html(new Email(['html' => $html, 'mime_disk' => 'local', 'mime_path' => 'email.eml'])))
        ->toContain('src="data:image/jpeg;base64,'.base64_encode($jpeg).'"');
    Http::assertNothingSent();
});

it('preserves preview content when stored MIME is absent malformed or exceeds its bound', function (string $state): void {
    $html = '<img src="cid:logo@email.local">';
    if ($state === 'malformed') {
        Storage::disk('local')->put('email.eml', 'not a MIME message');
    }
    if ($state === 'too large') {
        Storage::disk('local')->put('email.eml', str_repeat('x', 1024));
        Storage::shouldReceive('disk')->with('local')->andReturnSelf();
        Storage::shouldReceive('exists')->with('email.eml')->andReturnTrue();
        Storage::shouldReceive('size')->with('email.eml')->andReturn(52428801);
        Storage::shouldReceive('readStream')->never();
    }

    expect(app(EmailInlineImageResolver::class)->resolve(new Email(['html' => $html, 'mime_disk' => 'local', 'mime_path' => 'email.eml'])))
        ->toBe(['html' => $html, 'retryable' => $state === 'missing']);
    Http::assertNothingSent();
})->with(['missing', 'malformed', 'too large']);

it('marks archive metadata failure retryable without an existence precheck or content read', function (): void {
    Storage::shouldReceive('disk')->with('local')->andReturnSelf();
    Storage::shouldReceive('exists')->never();
    Storage::shouldReceive('size')->with('email.eml')->once()->andThrow(new RuntimeException('Fake storage metadata failure'));
    Storage::shouldReceive('readStream')->never();
    $html = '<img src="cid:logo@email.local">';

    expect(app(EmailInlineImageResolver::class)->resolve(new Email(['html' => $html, 'mime_disk' => 'local', 'mime_path' => 'email.eml'])))
        ->toBe(['html' => $html, 'retryable' => true]);
});

it('avoids MIME storage reads for messages with no CID images or an unconfigured archive or URL path', function (): void {
    Storage::shouldReceive('disk')->never();
    $resolver = app(EmailInlineImageResolver::class);

    expect($resolver->html(new Email(['html' => '<p>Ordinary message</p>', 'mime_disk' => 'local', 'mime_path' => 'email.eml'])))->toBe('<p>Ordinary message</p>')
        ->and($resolver->html(new Email(['html' => null])))->toBeNull()
        ->and($resolver->html(new Email(['html' => '<img src="cid:logo@email.local">', 'mime_disk' => 'unconfigured', 'mime_path' => 'email.eml'])))->toBe('<img src="cid:logo@email.local">')
        ->and($resolver->html(new Email(['html' => '<img src="cid:logo@email.local">', 'mime_disk' => 'local', 'mime_path' => 'https://tracker.test/email.eml'])))->toBe('<img src="cid:logo@email.local">');
    Http::assertNothingSent();
});

it('bounds total preview raster bytes while accepting an image at the individual byte limit', function (): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $contents = str_pad($png, 262144, "\0");
    $html = '<img src="cid:first@email.local"><img src="cid:second@email.local"><img src="cid:third@email.local">';
    $mime = (new MimeEmail)->from('from@example.test')->to('to@example.test')->html($html);
    foreach (['first', 'second', 'third'] as $name) {
        $mime->addPart((new DataPart($contents, $name.'.png', 'image/png'))->asInline()->setContentId($name.'@email.local'));
    }
    Storage::disk('local')->put('email.eml', $mime->toString());

    $preview = app(EmailInlineImageResolver::class)->html(new Email(['html' => $html, 'mime_disk' => 'local', 'mime_path' => 'email.eml']));

    expect(substr_count($preview, 'src="data:image/png;base64,'))->toBe(2)
        ->and($preview)->toContain('src="cid:third@email.local"');
    Http::assertNothingSent();
});

it('leaves ambiguous duplicate stored MIME content ids unresolved', function (): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $html = '<img src="cid:first@email.local"><img src="cid:second@email.local">';
    $mime = (new MimeEmail)->from('from@example.test')->to('to@example.test')->html($html)
        ->addPart((new DataPart($png, 'first.png', 'image/png'))->asInline()->setContentId('first@email.local'))
        ->addPart((new DataPart($png, 'second.png', 'image/png'))->asInline()->setContentId('second@email.local'))->toString();
    Storage::disk('local')->put('email.eml', str_replace('second@email.local', 'first@email.local', $mime));

    expect(app(EmailInlineImageResolver::class)->html(new Email(['html' => $html, 'mime_disk' => 'local', 'mime_path' => 'email.eml'])))->toBe($html);
    Http::assertNothingSent();
});

it('reads the recorded configured archive disk after the default disk changes', function (): void {
    Storage::fake('s3');
    config(['filesystems.default' => 'local']);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $html = '<img src="cid:logo@email.local">';
    $mime = (new MimeEmail)->from('from@example.test')->to('to@example.test')->html($html)
        ->addPart((new DataPart($png, 'logo.png', 'image/png'))->asInline()->setContentId('logo@email.local'))->toString();
    Storage::disk('s3')->put('email.eml', $mime);

    expect(app(EmailInlineImageResolver::class)->html(new Email(['html' => $html, 'mime_disk' => 's3', 'mime_path' => 'email.eml'])))
        ->toContain('src="data:image/png;base64,'.base64_encode($png).'"');
    Storage::disk('local')->assertDirectoryEmpty('/');
    Http::assertNothingSent();
});
