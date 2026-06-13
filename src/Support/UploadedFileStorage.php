<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Support;

use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

final class UploadedFileStorage
{
    /** @var array<string, string> */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** @var array<string, string> */
    private const DOCUMENT_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/plain' => 'txt',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly string $storageDir,
        private readonly string $publicBasePath,
        private readonly int $maxBytes = 5242880,
    ) {
    }

    /**
     * @return array{url: string, path: string, mimeType: string, size: int}
     */
    public function storeImage(UploadedFileInterface $file, string $folder = 'events'): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException('upload_failed');
        }

        $size = $file->getSize() ?? 0;
        if ($size <= 0 || $size > $this->maxBytes) {
            throw new RuntimeException('file_too_large');
        }

        $mimeType = strtolower((string) $file->getClientMediaType());
        if (!array_key_exists($mimeType, self::EXTENSIONS)) {
            throw new RuntimeException('unsupported_file_type');
        }

        $folder = trim(preg_replace('/[^a-z0-9_-]+/i', '-', $folder) ?? 'events', '-');
        $folder = $folder === '' ? 'events' : $folder;
        $targetDir = rtrim($this->storageDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $folder;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('upload_dir_unavailable');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . self::EXTENSIONS[$mimeType];
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
        $file->moveTo($targetPath);

        $imageInfo = @getimagesize($targetPath);
        if ($imageInfo === false) {
            @unlink($targetPath);
            throw new RuntimeException('unsupported_file_type');
        }

        $relativePath = $folder . '/' . $filename;
        $basePath = '/' . trim($this->publicBasePath, '/');

        return [
            'url' => $basePath . '/' . str_replace('\\', '/', $relativePath),
            'path' => $relativePath,
            'mimeType' => (string) ($imageInfo['mime'] ?? $mimeType),
            'size' => filesize($targetPath) ?: $size,
        ];
    }

    /**
     * @return array{url: string, path: string, mimeType: string, size: int, originalName: string}
     */
    public function storeDocument(UploadedFileInterface $file, string $folder = 'event-docs'): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException('upload_failed');
        }

        $size = $file->getSize() ?? 0;
        if ($size <= 0 || $size > $this->maxBytes) {
            throw new RuntimeException('file_too_large');
        }

        $mimeType = strtolower((string) $file->getClientMediaType());
        if (!array_key_exists($mimeType, self::DOCUMENT_EXTENSIONS)) {
            throw new RuntimeException('unsupported_file_type');
        }

        $folder = trim(preg_replace('/[^a-z0-9_-]+/i', '-', $folder) ?? 'event-docs', '-');
        $folder = $folder === '' ? 'event-docs' : $folder;
        $targetDir = rtrim($this->storageDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $folder;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('upload_dir_unavailable');
        }

        $ext = self::DOCUMENT_EXTENSIONS[$mimeType];
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
        $file->moveTo($targetPath);

        $relativePath = $folder . '/' . $filename;
        $basePath = '/' . trim($this->publicBasePath, '/');
        $originalName = (string) $file->getClientFilename();

        return [
            'url' => $basePath . '/' . str_replace('\\', '/', $relativePath),
            'path' => $relativePath,
            'mimeType' => $mimeType,
            'size' => filesize($targetPath) ?: $size,
            'originalName' => $originalName,
        ];
    }

    public function resolvePublicPath(string $relativePath): ?string
    {
        $relativePath = str_replace('\\', '/', ltrim($relativePath, '/'));
        $parts = array_filter(explode('/', $relativePath), static fn (string $part): bool => $part !== '' && $part !== '..');
        $safePath = implode(DIRECTORY_SEPARATOR, $parts);
        if ($safePath === '') {
            return null;
        }

        $base = realpath($this->storageDir);
        if ($base === false) {
            return null;
        }

        $path = realpath(rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safePath);
        if ($path === false || !is_file($path) || !str_starts_with($path, $base)) {
            return null;
        }

        return $path;
    }
}
