<?php

namespace App\Services;

use App\Models\Email;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZBateson\MailMimeParser\MailMimeParser;

class EmailInlineImageResolver
{
    private const MAX_MIME_BYTES = 52428800;

    private const MAX_IMAGE_BYTES = 262144;

    private const MAX_TOTAL_IMAGE_BYTES = 524288;

    public function __construct(private MailMimeParser $parser) {}

    public function html(Email $email): ?string
    {
        return $this->resolve($email)['html'];
    }

    /** @return array{html: ?string, retryable: bool} */
    public function resolve(Email $email): array
    {
        $html = $email->html;
        $result = ['html' => $html, 'retryable' => false];
        $diskName = $email->mime_disk;
        $path = $email->mime_path;
        if (! is_string($html) || ! str_contains($html, 'cid:')
            || ! is_string($diskName) || ! is_array(config('filesystems.disks.'.$diskName))
            || ! is_string($path) || $path === '' || str_contains($path, '://')) {
            return $result;
        }

        try {
            $images = $this->images($diskName, $path);
        } catch (Throwable) {
            return ['html' => $html, 'retryable' => true];
        }
        if ($images === []) {
            return $result;
        }
        try {
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
            $changed = false;
            foreach ($document->getElementsByTagName('img') as $image) {
                $source = $image->getAttribute('src');
                if ($image->namespaceURI !== 'http://www.w3.org/1999/xhtml' || ! str_starts_with($source, 'cid:')) {
                    continue;
                }
                $cid = substr($source, 4);
                if (isset($images[$cid])) {
                    $image->setAttribute('src', $images[$cid]);
                    $changed = true;
                }
            }

            $result['html'] = $changed ? $document->saveHtml() : $html;
        } catch (Throwable) {
            return $result;
        }

        return $result;
    }

    /** @return array<string, string> */
    private function images(string $diskName, string $path): array
    {
        $disk = Storage::disk($diskName);
        if ($disk->size($path) > self::MAX_MIME_BYTES) {
            return [];
        }
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('Unable to read inline image archive.');
        }

        try {
            $message = $this->parser->parse($stream, autoClose: true);
            $parts = $message->getAllAttachmentParts();
            if (count($parts) > 25) {
                return [];
            }
            $images = [];
            $seen = [];
            $totalBytes = 0;
            foreach ($parts as $part) {
                $cid = $part->getContentId();
                if (! is_string($cid) || strlen($cid) > 128 || ! preg_match('/\A[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+\z/', $cid)) {
                    continue;
                }
                if (isset($seen[$cid])) {
                    return [];
                }
                $seen[$cid] = true;
                $mime = $part->getContentType();
                if ($part->getContentDisposition() !== 'inline' || ! in_array($mime, ['image/png', 'image/jpeg'], true)) {
                    continue;
                }
                $content = $part->getContentStream()?->read(self::MAX_IMAGE_BYTES + 1);
                if (! is_string($content) || $content === '' || strlen($content) > self::MAX_IMAGE_BYTES || $totalBytes + strlen($content) > self::MAX_TOTAL_IMAGE_BYTES) {
                    continue;
                }
                $dimensions = @getimagesizefromstring($content);
                if (! is_array($dimensions) || ($dimensions['mime'] ?? null) !== $mime
                    || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > 4096 || $dimensions[1] > 4096) {
                    continue;
                }
                $totalBytes += strlen($content);
                $images[$cid] = 'data:'.$mime.';base64,'.base64_encode($content);
            }

            return $images;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
