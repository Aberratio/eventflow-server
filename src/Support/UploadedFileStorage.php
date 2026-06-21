<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Support;

use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

final class UploadedFileStorage
{
    private const MAX_IMAGE_PIXELS = 40000000;

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

    /** @var array<string, string> */
    private const RESULT_IMPORT_EXTENSIONS = [
        'text/csv' => 'csv',
        'text/plain' => 'csv',
        'application/csv' => 'csv',
        'application/pdf' => 'pdf',
        'application/vnd.ms-excel' => 'csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/octet-stream' => '',
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

        $clientMimeType = strtolower((string) $file->getClientMediaType());
        if (
            $clientMimeType !== ''
            && $clientMimeType !== 'application/octet-stream'
            && !array_key_exists($clientMimeType, self::EXTENSIONS)
        ) {
            throw new RuntimeException('unsupported_file_type');
        }

        $folder = trim(preg_replace('/[^a-z0-9_-]+/i', '-', $folder) ?? 'events', '-');
        $folder = $folder === '' ? 'events' : $folder;
        $targetDir = rtrim($this->storageDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $folder;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('upload_dir_unavailable');
        }

        $basename = bin2hex(random_bytes(16));
        $tempPath = $targetDir . DIRECTORY_SEPARATOR . $basename . '.upload';
        $file->moveTo($tempPath);

        $storedSize = filesize($tempPath) ?: $size;
        if ($storedSize <= 0 || $storedSize > $this->maxBytes) {
            @unlink($tempPath);
            throw new RuntimeException('file_too_large');
        }

        $imageInfo = @getimagesize($tempPath);
        if ($imageInfo === false) {
            @unlink($tempPath);
            throw new RuntimeException('unsupported_file_type');
        }

        $mimeType = strtolower((string) ($imageInfo['mime'] ?? ''));
        if (!array_key_exists($mimeType, self::EXTENSIONS)) {
            @unlink($tempPath);
            throw new RuntimeException('unsupported_file_type');
        }

        $width = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);
        if ($width <= 0 || $height <= 0) {
            @unlink($tempPath);
            throw new RuntimeException('invalid_image_dimensions');
        }
        if ($width * $height > self::MAX_IMAGE_PIXELS) {
            @unlink($tempPath);
            throw new RuntimeException('image_dimensions_too_large');
        }

        $filename = $basename . '.' . self::EXTENSIONS[$mimeType];
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
        if (!rename($tempPath, $targetPath)) {
            @unlink($tempPath);
            throw new RuntimeException('upload_failed');
        }

        $relativePath = $folder . '/' . $filename;
        $basePath = '/' . trim($this->publicBasePath, '/');

        return [
            'url' => $basePath . '/' . str_replace('\\', '/', $relativePath),
            'path' => $relativePath,
            'mimeType' => $mimeType,
            'size' => filesize($targetPath) ?: $storedSize,
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

        if (str_starts_with($mimeType, 'image/')) {
            $imageInfo = @getimagesize($targetPath);
            $realMimeType = strtolower((string) ($imageInfo['mime'] ?? ''));
            $width = (int) ($imageInfo[0] ?? 0);
            $height = (int) ($imageInfo[1] ?? 0);
            if (
                $imageInfo === false
                || !array_key_exists($realMimeType, self::EXTENSIONS)
                || $realMimeType !== $mimeType
                || $width <= 0
                || $height <= 0
            ) {
                @unlink($targetPath);
                throw new RuntimeException('unsupported_file_type');
            }
            if ($width * $height > self::MAX_IMAGE_PIXELS) {
                @unlink($targetPath);
                throw new RuntimeException('image_dimensions_too_large');
            }
        }

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

    /**
     * @return array{url: string, path: string, absolutePath: string, mimeType: string, size: int, originalName: string}
     */
    public function storeResultImport(UploadedFileInterface $file, string $folder = 'result-imports'): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException('upload_failed');
        }

        $size = $file->getSize() ?? 0;
        if ($size <= 0 || $size > $this->maxBytes) {
            throw new RuntimeException('file_too_large');
        }

        $originalName = (string) $file->getClientFilename();
        $clientMimeType = strtolower((string) $file->getClientMediaType());
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedByExtension = in_array($extension, ['csv', 'xlsx', 'pdf'], true);
        if (!$allowedByExtension && !array_key_exists($clientMimeType, self::RESULT_IMPORT_EXTENSIONS)) {
            throw new RuntimeException('unsupported_file_type');
        }

        $ext = $extension === 'xlsx' ? 'xlsx' : ($extension === 'pdf' ? 'pdf' : 'csv');
        if ($clientMimeType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
            $ext = 'xlsx';
        } elseif ($clientMimeType === 'application/pdf') {
            $ext = 'pdf';
        }

        $folder = trim(preg_replace('/[^a-z0-9_-]+/i', '-', $folder) ?? 'result-imports', '-');
        $folder = $folder === '' ? 'result-imports' : $folder;
        $targetDir = rtrim($this->storageDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $folder;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('upload_dir_unavailable');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
        $file->moveTo($targetPath);

        $relativePath = $folder . '/' . $filename;
        $basePath = '/' . trim($this->publicBasePath, '/');

        return [
            'url' => $basePath . '/' . str_replace('\\', '/', $relativePath),
            'path' => $relativePath,
            'absolutePath' => $targetPath,
            'mimeType' => $ext === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : ($ext === 'pdf' ? 'application/pdf' : 'text/csv'),
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
