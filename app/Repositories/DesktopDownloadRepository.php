<?php
// Catálogo Downloads: separa produtos e mantém exclusão restrita a rascunhos sem telemetria.
// Materialização de runtime para publicação restrita da separação de produtos.
namespace App\Repositories;

use App\Core\Database;
use App\Core\SqlHelper;
use PDO;

final class DesktopDownloadRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    public function available(): bool
    {
        return SqlHelper::hasTable($this->pdo, 'bi_desktop_release_packages')
            && SqlHelper::hasTable($this->pdo, 'bi_desktop_download_events');
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        if (!$this->available()) return [];
        $sql = "SELECT p.*, COUNT(e.id) AS downloads
                  FROM bi_desktop_release_packages p
             LEFT JOIN bi_desktop_download_events e ON e.package_id = p.id
              GROUP BY p.id
              ORDER BY CASE p.status WHEN 'published' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END, p.created_at DESC, p.id DESC";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if (!$this->available() || $id < 1) return null;
        $stmt = $this->pdo->prepare('SELECT * FROM bi_desktop_release_packages WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function latestPublished(string $productKey, string $platform, string $channel = 'stable'): ?array
    {
        if (!$this->available()) return null;
        $sql = "SELECT * FROM bi_desktop_release_packages WHERE product_key = ? AND platform = ? AND channel = ? AND status = 'published' ORDER BY published_at DESC NULLS LAST, id DESC LIMIT 1";
        if (!SqlHelper::isPostgres()) {
            $sql = "SELECT * FROM bi_desktop_release_packages WHERE product_key = ? AND platform = ? AND channel = ? AND status = 'published' ORDER BY published_at DESC, id DESC LIMIT 1";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$productKey, $platform, $channel]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $sql = 'INSERT INTO bi_desktop_release_packages (product_key, version_name, platform, channel, original_filename, storage_key, mime_type, size_bytes, checksum_sha256, notes, status, created_by) VALUES (:product_key, :version_name, :platform, :channel, :original_filename, :storage_key, :mime_type, :size_bytes, :checksum_sha256, :notes, \'draft\', :created_by)';
        if (SqlHelper::isPostgres()) {
            $stmt = $this->pdo->prepare($sql . ' RETURNING id');
            $stmt->execute($data);
            return (int) $stmt->fetchColumn();
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
        return (int) $this->pdo->lastInsertId();
    }

    public function publish(int $id, int $userId): void
    {
        $target = $this->find($id);
        if (!$target || ($target['status'] ?? '') === 'archived') throw new \DomainException('release_not_found');
        $this->pdo->beginTransaction();
        try {
            $archive = $this->pdo->prepare("UPDATE bi_desktop_release_packages SET status = 'archived', archived_at = NOW(), archived_by = ? WHERE product_key = ? AND platform = ? AND channel = ? AND status = 'published' AND id <> ?");
            $archive->execute([$userId, $target['product_key'], $target['platform'], $target['channel'], $id]);
            $publish = $this->pdo->prepare("UPDATE bi_desktop_release_packages SET status = 'published', published_at = NOW(), archived_at = NULL, archived_by = NULL WHERE id = ?");
            $publish->execute([$id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function archive(int $id, int $userId): void
    {
        $stmt = $this->pdo->prepare("UPDATE bi_desktop_release_packages SET status = 'archived', archived_at = NOW(), archived_by = ? WHERE id = ? AND status <> 'archived'");
        $stmt->execute([$userId, $id]);
    }

    public function deleteDraftWithoutDownloads(int $id): bool
    {
        if (!$this->available() || $id < 1) return false;
        $sql = "DELETE FROM bi_desktop_release_packages p WHERE p.id = ? AND p.status = 'draft' AND NOT EXISTS (SELECT 1 FROM bi_desktop_download_events e WHERE e.package_id = p.id)";
        if (!SqlHelper::isPostgres()) {
            $sql = "DELETE FROM bi_desktop_release_packages WHERE id = ? AND status = 'draft' AND NOT EXISTS (SELECT 1 FROM bi_desktop_download_events WHERE package_id = ?)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$id, $id]);
            return $stmt->rowCount() === 1;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }

    public function recordDownload(int $packageId, ?int $userId, ?int $tenantId, string $source, ?string $ipHash, ?string $agentHash): void
    {
        if (!$this->available()) return;
        $stmt = $this->pdo->prepare('INSERT INTO bi_desktop_download_events (package_id, user_id, tenant_id, source, ip_hash, user_agent_hash) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$packageId, $userId, $tenantId, $source, $ipHash, $agentHash]);
    }

    /** @return array<string,int> */
    public function stats(int $id): array
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) downloads, COUNT(DISTINCT user_id) usuarios, COUNT(DISTINCT tenant_id) tenants, COUNT(DISTINCT CASE WHEN source = 'worklist' THEN id END) worklist FROM bi_desktop_download_events WHERE package_id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['downloads' => (int) ($row['downloads'] ?? 0), 'usuarios' => (int) ($row['usuarios'] ?? 0), 'tenants' => (int) ($row['tenants'] ?? 0), 'worklist' => (int) ($row['worklist'] ?? 0)];
    }
}
