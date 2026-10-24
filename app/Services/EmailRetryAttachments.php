<?php

namespace App\Services;

use App\Models\Email;
use Dom\HTMLDocument;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZBateson\MailMimeParser\Header\Part\NameValuePart;
use ZBateson\MailMimeParser\MailMimeParser;

class EmailRetryAttachments
{
    private const MAX_MIME_BYTES = 52428800;

    private const MAX_ATTACHMENT_BYTES = 31457280;

    public function __construct(private MailMimeParser $parser) {}

    /** @return array<int, array{filename: string, content: string, content_type: string, content_type_parameters?: array<string, string>, content_id?: string}> */
    public function forEmail(Email $email): array
    {
        $expected = $email->attachments;
        $references = $this->referencedContentIds($email->html);
        if ($expected->isEmpty() && $references === []) {
            return [];
        }
        $diskName = $email->mime_disk;
        $path = $email->mime_path;
        if (! is_string($diskName) || ! is_array(config('filesystems.disks.'.$diskName))
            || ! is_string($path) || $path === '' || str_contains($path, '://')) {
            throw new RuntimeException('The original email attachment archive is unavailable.');
        }
        $disk = Storage::disk($diskName);
        if ($disk->size($path) > self::MAX_MIME_BYTES) {
            throw new RuntimeException('The original email attachment archive is too large.');
        }
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('The original email attachment archive is unreadable.');
        }
        try {
            $mime = stream_get_contents($stream, self::MAX_MIME_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if (! is_string($mime) || strlen($mime) > self::MAX_MIME_BYTES) {
            throw new RuntimeException('The original email attachment archive is too large.');
        }
        $parts = $this->parser->parse($mime, false)->getAllAttachmentParts();
        if (count($parts) !== $expected->count() || count($parts) > 25) {
            throw new RuntimeException('The original email attachment archive is incomplete.');
        }
        $attachments = [];
        $ids = [];
        $bytes = 0;
        $remaining = $expected->values();
        foreach ($parts as $part) {
            $contentStream = $part->getBinaryContentStream();
            $content = $contentStream === null ? '' : Utils::copyToString($contentStream, self::MAX_ATTACHMENT_BYTES - $bytes + 1);
            if ($bytes + strlen($content) > self::MAX_ATTACHMENT_BYTES) {
                throw new RuntimeException('The original email attachments exceed the size limit.');
            }
            $bytes += strlen($content);
            $filename = $part->getFilename();
            $type = $part->getContentType();
            $inline = $part->getContentDisposition() === 'inline';
            $match = $remaining->search(fn ($item): bool => $item->filename === $filename
                && strtolower(trim(explode(';', $item->content_type ?? 'application/octet-stream', 2)[0])) === strtolower($type) && (int) $item->size === strlen($content)
                && $item->disposition === ($inline ? 'inline' : 'attachment'));
            if ($match === false || ! is_string($filename)) {
                throw new RuntimeException('The original email attachment archive does not match its records.');
            }
            $remaining->forget($match);
            $attachment = ['filename' => $filename, 'content' => base64_encode($content), 'content_type' => $type];
            foreach ($part->getHeader('Content-Type')?->getParts() ?? [] as $parameter) {
                if ($parameter instanceof NameValuePart) {
                    $attachment['content_type_parameters'][strtolower($parameter->getName())] = $parameter->getValue();
                }
            }
            if ($inline) {
                $id = $part->getContentId();
                if (! is_string($id) || strlen($id) > 128 || ! preg_match('/\A[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+\z/', $id) || isset($ids[$id])) {
                    throw new RuntimeException('The original email inline attachment identity is invalid.');
                }
                $ids[$id] = true;
                $attachment['content_id'] = $id;
            }
            $attachments[] = $attachment;
        }
        if (array_diff($references, array_keys($ids)) !== []) {
            throw new RuntimeException('The original email inline attachments are unavailable.');
        }

        return $attachments;
    }

    /** @return array<int, string> */
    private function referencedContentIds(?string $html): array
    {
        if ($html === null || stripos($html, 'cid:') === false) {
            return [];
        }
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        $references = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            foreach (['src', 'href', 'background', 'poster'] as $attribute) {
                $value = $element->getAttribute($attribute) ?? '';
                if (stripos($value, 'cid:') === 0) {
                    $references[] = substr($value, 4);
                }
            }
            $css = $element->localName === 'style' ? $element->textContent : ($element->getAttribute('style') ?? '');
            preg_match_all('/url\(\s*["\']?cid:([^\s)"\']+)/i', $css, $matches);
            array_push($references, ...$matches[1]);
        }

        return array_values(array_unique($references));
    }
}
