<?php
// Catálogo Downloads: ZIP privado por produto; View/Desktop é o único produto distribuído pela Worklist.
namespace App\Services;

use App\Repositories\DesktopDownloadRepository;

final class DesktopDownloadService
{
    public const PRODUCT_VIEW_DESKTOP = 'voxel_view_desktop';
    public const PRODUCT_ROUTER_DESKTOP = 'voxel_router_desktop';

    private const MAX_BYTES = 1024 * 1024 * 1024;
    private const MAX_FILES = 256;
    private const MAX_UNCOMPRESSED_BYTES = 2 * 1024 * 1024 * 1024;
    private const STORAGE_ROOT = BASE_PATH . '/storage/downloads';
    private const PRODUCT_DIRECTORIES = [
        self::PRODUCT_VIEW_DESKTOP => 'voxel-view-desktop',
        self::PRODUCT_ROUTER_DESKTOP => 'voxel-router-desktop',
    ];

    public function __construct(private ?DesktopDownloadRepository $repository = null)
    {
        $this->repository ??= new DesktopDownloadRepository();
    }

    public function repository(): DesktopDownloadRepository { return $this->repository; }

    /** @param array<string,mixed> $file */
    public function upload(array $file, array $input, int $userId): int
    {
        if (!$this->repository->available()) throw new \DomainException('catalog_unavailable');
        $validated = $this->validateZip($file);
        $productKey = strtolower(trim((string) ($input['product_key'] ?? '')));
        $this->assertCurrentProduct($productKey);
        $version = trim((string) ($input['version_name'] ?? ''));
        if (!preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+][A-Za-z0-9.-]+)?$/', $version)) throw new \DomainException('invalid_version');
        $platform = strtolower(trim((string) ($input['platform'] ?? 'windows')));
        $channel = strtolower(trim((string) ($input['channel'] ?? 'stable')));
        if (!in_array($platform, ['windows', 'mac', 'linux'], true) || !in_array($channel, ['stable', 'beta'], true)) throw new \DomainException('invalid_release_target');
        $notes = trim(strip_tags((string) ($input['notes'] ?? '')));
        if (mb_strlen($notes) > 6000) throw new \DomainException('notes_too_long');

        $directory = self::PRODUCT_DIRECTORIES[$productKey];
        $targetDirectory = self::STORAGE_ROOT . '/' . $directory;
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0700, true) && !is_dir($targetDirectory)) throw new \RuntimeException('storage_unavailable');
        $storageKey = $directory . '/' . bin2hex(random_bytes(20)) . '.zip';
        $target = self::STORAGE_ROOT . '/' . $storageKey;
        if (!move_uploaded_file($validated['tmp_name'], $target)) throw new \RuntimeException('storage_write_failed');
        @chmod($target, 0600);

        try {
            return $this->repository->create([
                ':product_key' => $productKey,
                ':version_name' => $version,
                ':platform' => $platform,
                ':channel' => $channel,
                ':original_filename' => $validated['original_filename'],
                ':storage_key' => $storageKey,
                ':mime_type' => $validated['mime_type'],
                ':size_bytes' => $validated['size_bytes'],
                ':checksum_sha256' => $validated['checksum_sha256'],
                ':notes' => $notes !== '' ? $notes : null,
                ':created_by' => $userId,
            ]);
        } catch (\Throwable $e) {
            @unlink($target);
            throw $e;
        }
    }

    /** @param array<string,mixed> $file @return array{tmp_name:string,original_filename:string,mime_type:string,size_bytes:int,checksum_sha256:string} */
    private function validateZip(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) throw new \DomainException('invalid_upload');
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_BYTES) throw new \DomainException('invalid_size');
        $original = basename((string) ($file['name'] ?? ''));
        return self::validateZipArchive((string) $file['tmp_name'], $original, $size);
    }

    /** @return array{tmp_name:string,original_filename:string,mime_type:string,size_bytes:int,checksum_sha256:string} */
    public static function validateZipArchive(string $path, string $original, int $size): array
    {
        if (!is_file($path)) throw new \DomainException('invalid_upload');
        if ($size < 1 || $size > self::MAX_BYTES) throw new \DomainException('invalid_size');
        $original = basename($original);
        if (!preg_match('/\.zip$/i', $original)) throw new \DomainException('invalid_extension');
        $handle = @fopen($path, 'rb');
        $signature = $handle ? fread($handle, 4) : false;
        if (is_resource($handle)) fclose($handle);
        if ($signature !== "PK\x03\x04") throw new \DomainException('invalid_signature');
        if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('zip_unavailable');
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CHECKCONS) !== true) throw new \DomainException('invalid_archive');
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_FILES) throw new \DomainException('invalid_archive_size');
            $expanded = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');
                if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/') || str_starts_with($name, '\\')) throw new \DomainException('unsafe_archive');
                $expanded += (int) ($stat['size'] ?? 0);
                if ($expanded > self::MAX_UNCOMPRESSED_BYTES) throw new \DomainException('invalid_archive_size');
            }
        } finally { $zip->close(); }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string) finfo_file($finfo, $path) : 'application/zip';
        if ($finfo) finfo_close($finfo);
        return ['tmp_name' => $path, 'original_filename' => $original, 'mime_type' => $mime, 'size_bytes' => $size, 'checksum_sha256' => hash_file('sha256', $path)];
    }

    /** @return array<string,mixed>|null */
    public function resolvePublished(string $platform, string $channel, string $productKey = self::PRODUCT_VIEW_DESKTOP): ?array
    {
        $this->assertCurrentProduct($productKey);
        return $this->repository->latestPublished($productKey, $platform, $channel);
    }

    /** @return array<string,mixed> */
    public function publish(int $packageId, int $userId): array
    {
        if (!$this->repository->available()) throw new \DomainException('catalog_unavailable');
        $package = $this->repository->find($packageId);
        if (!$package) throw new \DomainException('not_found');
        $this->assertCurrentProduct((string) ($package['product_key'] ?? ''));
        if (!$this->pathFor($package)) throw new \DomainException('package_file_missing');
        $this->repository->publish($packageId, $userId);
        return $this->repository->find($packageId) ?? $package;
    }

    /** @return array<string,mixed> */
    public function archive(int $packageId, int $userId): array
    {
        if (!$this->repository->available()) throw new \DomainException('catalog_unavailable');
        $package = $this->repository->find($packageId);
        if (!$package) throw new \DomainException('not_found');
        $this->repository->archive($packageId, $userId);
        return $this->repository->find($packageId) ?? $package;
    }

    /** @return array<string,mixed> */
    public function deleteDraft(int $packageId): array
    {
        if (!$this->repository->available()) throw new \DomainException('catalog_unavailable');
        $package = $this->repository->find($packageId);
        if (!$package) throw new \DomainException('not_found');
        if (($package['status'] ?? '') !== 'draft') throw new \DomainException('package_not_deletable');
        $path = $this->pathFor($package);
        $quarantinePath = null;
        if ($path !== null) {
            $quarantinePath = $path . '.deleting-' . bin2hex(random_bytes(8));
            if (!@rename($path, $quarantinePath)) throw new \RuntimeException('storage_delete_failed');
        }
        if (!$this->repository->deleteDraftWithoutDownloads($packageId)) {
            if ($path !== null && $quarantinePath !== null) @rename($quarantinePath, $path);
            throw new \DomainException('package_not_deletable');
        }
        if ($quarantinePath !== null && !@unlink($quarantinePath)) throw new \RuntimeException('storage_delete_failed');
        return $package;
    }

    /** @return resource|null */
    public function openPrivatePackage(array $package)
    {
        $path = $this->pathFor($package);
        return $path !== null ? @fopen($path, 'rb') : null;
    }

    public function pathFor(array $package): ?string
    {
        $key = (string) ($package['storage_key'] ?? '');
        if (!$this->isSafeStorageKey($key)) return null;
        $path = self::STORAGE_ROOT . '/' . $key;
        return is_file($path) ? $path : null;
    }

    private function assertCurrentProduct(string $productKey): void
    {
        if (!array_key_exists($productKey, self::PRODUCT_DIRECTORIES)) throw new \DomainException('invalid_product');
    }

    private function isSafeStorageKey(string $key): bool
    {
        return (bool) preg_match('#^(?:voxel-desktop|voxel-view-desktop|voxel-router-desktop)/[a-f0-9]{40}\.zip$#', $key);
    }
}
