<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Converte caminhos de snapshots entre o contrato persistido relativo e o
 * caminho absoluto do storage do ambiente atual.
 *
 * O resolvedor nunca permite sair do storage configurado nem do diretório
 * tenant/report esperado pelo chamador.
 */
final class PdfSnapshotPathResolver
{
    public static function storageBasePath(): string
    {
        $base = defined('STORAGE_PATH')
            ? (string) STORAGE_PATH
            : dirname(__DIR__, 2) . '/storage';

        return rtrim($base, '/\\');
    }

    /**
     * @throws RuntimeException quando o arquivo não estiver no prefixo esperado.
     */
    public static function relativePathFor(string $absolutePath, string $expectedPrefix): string
    {
        $storageRoot = realpath(self::storageBasePath());
        $pathReal = realpath($absolutePath);
        if ($storageRoot === false || $pathReal === false || !self::isWithin($pathReal, $storageRoot)) {
            throw new RuntimeException('Snapshot PDF fora do storage configurado.');
        }

        $relative = self::relativeToStorage($pathReal, $storageRoot);
        $prefix = self::normalizePrefix($expectedPrefix);
        if (!str_starts_with($relative, $prefix)) {
            throw new RuntimeException('Snapshot PDF fora do escopo esperado.');
        }

        return $relative;
    }

    /**
     * Resolve caminho relativo novo e caminho absoluto legado, sempre dentro
     * do storage atual e do prefixo tenant/report fornecido.
     */
    public static function resolve(string $storedPath, string $expectedPrefix): ?string
    {
        $storedPath = trim($storedPath);
        if ($storedPath === '') {
            return null;
        }

        $storageRoot = realpath(self::storageBasePath());
        if ($storageRoot === false) {
            return null;
        }

        $candidate = self::isAbsolute($storedPath)
            ? $storedPath
            : $storageRoot . '/' . ltrim(str_replace('\\', '/', $storedPath), '/');
        $pathReal = realpath($candidate);
        if ($pathReal === false || !is_file($pathReal) || !self::isWithin($pathReal, $storageRoot)) {
            return null;
        }

        $relative = self::relativeToStorage($pathReal, $storageRoot);
        if (!str_starts_with($relative, self::normalizePrefix($expectedPrefix))) {
            return null;
        }

        return $pathReal;
    }

    private static function normalizePrefix(string $prefix): string
    {
        $prefix = trim(str_replace('\\', '/', $prefix), '/');
        if ($prefix === '' || str_contains($prefix, '..')) {
            throw new RuntimeException('Prefixo de storage inválido.');
        }

        return $prefix . '/';
    }

    private static function relativeToStorage(string $path, string $storageRoot): string
    {
        return ltrim(str_replace('\\', '/', substr($path, strlen($storageRoot))), '/');
    }

    private static function isWithin(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || (bool) preg_match('~^[A-Za-z]:[\\\\/]~', $path);
    }
}
