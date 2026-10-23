<?php

use App\Models\Email;
use App\Models\EmailAttachment;
use App\Services\EmailRetryAttachments;
use App\Services\MimeMessageBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\Mime\Email as MimeEmail;
use Tests\TestCase;
use ZBateson\MailMimeParser\Header\Part\NameValuePart;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\IMessagePart;
use ZBateson\MailMimeParser\MailMimeParser;

uses(TestCase::class);

it('rejects oversized retry archives before reading any content', function (): void {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('size')->once()->with('original.eml')->andReturn(52428801);
    $disk->shouldNotReceive('readStream');
    Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);
    $email = (new Email(['mime_disk' => 'local', 'mime_path' => 'original.eml']))
        ->setRelation('attachments', new Collection([new EmailAttachment(['filename' => 'file.pdf'])]));

    expect(fn () => app(EmailRetryAttachments::class)->forEmail($email))->toThrow(RuntimeException::class, 'too large');
});

it('retains at most twenty-five archived attachments without losing zero-byte documents', function (int $count): void {
    Storage::fake('local');
    $mime = (new MimeEmail)->from('sender@example.test')->to('reader@example.test')->text('Original');
    $metadata = new Collection;
    for ($index = 0; $index < $count; $index++) {
        $mime->attach('', "file-{$index}.pdf", 'application/pdf');
        $metadata->push(new EmailAttachment(['filename' => "file-{$index}.pdf", 'content_type' => 'application/pdf', 'size' => 0, 'disposition' => 'attachment']));
    }
    Storage::disk('local')->put('original.eml', $mime->toString());
    $email = (new Email(['mime_disk' => 'local', 'mime_path' => 'original.eml']))->setRelation('attachments', $metadata);

    if ($count > 25) {
        expect(fn () => app(EmailRetryAttachments::class)->forEmail($email))->toThrow(RuntimeException::class, 'incomplete');

        return;
    }
    $attachments = app(EmailRetryAttachments::class)->forEmail($email);
    expect($attachments)->toHaveCount(25)
        ->and($attachments[0]['filename'])->toBe('file-0.pdf')
        ->and($attachments[0]['content'])->toBe('')
        ->and($attachments[0]['content_type'])->toBe('application/pdf');
})->with([25, 26]);

it('does not mistake ordinary CID prose for an inline resource URL', function (string $html): void {
    Storage::shouldReceive('disk')->never();
    $email = (new Email(['html' => $html]))->setRelation('attachments', new Collection);

    expect(app(EmailRetryAttachments::class)->forEmail($email))->toBe([]);
})->with(['<p>Acid: inspect carefully.</p>', '<p>acid:reader@example.com</p>', '<p>Use cid: URLs in image attributes.</p>', '<img alt="src=cid:reader@example.com">']);

it('requires an archive for actual inline URL attributes and CSS resources', function (string $html): void {
    $this->withoutDeprecationHandling();
    $email = (new Email(['html' => $html]))->setRelation('attachments', new Collection);

    expect(fn () => app(EmailRetryAttachments::class)->forEmail($email))->toThrow(RuntimeException::class, 'archive is unavailable');
})->with(['<img src="cid:logo&#64;example.com">', '<table background="cid:logo@example.com"></table>', '<div style="background:url(cid:logo@example.com)"></div>', '<style>.logo{background:url("cid:logo@example.com")}</style>']);

it('recovers the exact binary bytes of a non-UTF8 text attachment', function (): void {
    Storage::fake('local');
    $bytes = "caf\xe9";
    $mime = "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=fixture\r\n\r\n--fixture\r\nContent-Type: text/plain\r\n\r\nOriginal\r\n--fixture\r\nContent-Type: text/csv; Charset=ISO-8859-1; Name=legacy.csv\r\nContent-Disposition: attachment; filename=names.csv\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode($bytes)."\r\n--fixture--\r\n";
    Storage::disk('local')->put('original.eml', $mime);
    $email = (new Email(['mime_disk' => 'local', 'mime_path' => 'original.eml']))->setRelation('attachments', new Collection([
        new EmailAttachment(['filename' => 'names.csv', 'content_type' => 'TeXt/CsV; charset=ISO-8859-1', 'size' => (string) strlen($bytes), 'disposition' => 'attachment']),
    ]));

    $attachments = app(EmailRetryAttachments::class)->forEmail($email);
    expect(base64_decode($attachments[0]['content']))->toBe($bytes)
        ->and($attachments[0]['content_type'])->toBe('text/csv');
    $resent = app(MimeMessageBuilder::class)->build(from: 'sender@example.test', to: ['reader@example.test'], cc: [], bcc: [], replyTo: null,
        subject: 'Charset fixture', html: null, text: 'Original', attachments: $attachments);
    $part = app(MailMimeParser::class)->parse($resent, false)->getAllAttachmentParts()[0];
    expect($part->getHeaderParameter('Content-Type', 'charset'))->toBe('ISO-8859-1')
        ->and($part->getBinaryContentStream()->getContents())->toBe($bytes)
        ->and(collect($part->getHeader('Content-Type')->getParts())->filter(fn ($parameter): bool => $parameter instanceof NameValuePart && strtolower($parameter->getName()) === 'name'))->toHaveCount(1);
});

it('reads every decoded attachment chunk instead of assuming one read fills the buffer', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('original.eml', 'fictional archive');
    $stream = Mockery::mock(StreamInterface::class);
    $stream->shouldReceive('read')->andReturn('first-', 'second');
    $stream->shouldReceive('eof')->andReturn(false, false, true);
    $part = Mockery::mock(IMessagePart::class);
    $part->shouldReceive('getContentStream')->andReturn($stream);
    $part->shouldReceive('getBinaryContentStream')->andReturn($stream);
    $part->shouldReceive('getFilename')->andReturn('file.pdf');
    $part->shouldReceive('getContentType')->andReturn('application/pdf');
    $part->shouldReceive('getContentDisposition')->andReturn('attachment');
    $part->shouldReceive('getHeader')->with('Content-Type')->andReturnNull();
    $message = Mockery::mock(IMessage::class);
    $message->shouldReceive('getAllAttachmentParts')->andReturn([$part]);
    $parser = Mockery::mock(MailMimeParser::class);
    $parser->shouldReceive('parse')->andReturn($message);
    $email = (new Email(['mime_disk' => 'local', 'mime_path' => 'original.eml']))->setRelation('attachments', new Collection([
        new EmailAttachment(['filename' => 'file.pdf', 'content_type' => 'application/pdf', 'size' => 12, 'disposition' => 'attachment']),
    ]));

    $attachments = (new EmailRetryAttachments($parser))->forEmail($email);
    expect(base64_decode($attachments[0]['content']))->toBe('first-second');
});
