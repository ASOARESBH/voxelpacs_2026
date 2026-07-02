<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Services\OrthancService;

class PacsController extends Controller {

    // ── Endpoint público: chamado pelo cron-job.org para ping automático agendado do servidor PACS ──
    public function cronPing(): void {
        @set_time_limit(30);
        @ini_set('display_errors', '0');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $pdo    = Database::getInstance();
        $token  = $_GET['token'] ?? '';
        $inicio = microtime(true);

        $servidor = $pdo->query("SELECT * FROM bi_pacs_servidor WHERE id = 1")->fetch(\PDO::FETCH_ASSOC);

        if (!$servidor || empty($servidor['sync_cron_token']) || !hash_equals((string)$servidor['sync_cron_token'], (string)$token)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Token inválido ou não configurado.']);
            exit;
        }

        if ((int)$servidor['sync_auto_ativo'] !== 1) {
            echo json_encode(['success' => false, 'message' => 'Ping automático está desativado nas configurações.']);
            exit;
        }

        $orthanc = new OrthancService(
            $servidor['url'],
            $servidor['usuario'] ?? null,
            $servidor['senha']   ?? null,
            (int)($servidor['timeout'] ?? 30)
        );

        $ping     = $orthanc->ping();
        $sucesso  = $ping['success'];
        $mensagem = $sucesso
            ? 'Ping OK — Orthanc respondeu normalmente.'
            : ('Falha no ping: ' . ($ping['error'] ?? 'erro desconhecido'));

        try {
            if ($sucesso) {
                $pdo->prepare("
                    UPDATE bi_pacs_servidor SET status_ping='online', ultimo_ping=NOW(), sync_ultima_execucao=NOW() WHERE id=1
                ")->execute();
            } else {
                $pdo->prepare("
                    UPDATE bi_pacs_servidor SET status_ping='erro', ultimo_ping=NOW(), sync_ultima_execucao=NOW(), observacoes=? WHERE id=1
                ")->execute([$ping['error'] ?? null]);
            }
        } catch (\Exception $e) {
            error_log("[PACS] Erro ao atualizar status no ping agendado: " . $e->getMessage());
        }

        $tempoMs = (int)((microtime(true) - $inicio) * 1000);

        try {
            $pdo->prepare("
                INSERT INTO bi_pacs_sync_execucoes (servidor_id, executado_em, origem, sucesso, tempo_resposta_ms, mensagem, ip_origem)
                VALUES (1, NOW(), 'cron-job.org', ?, ?, ?, ?)
            ")->execute([$sucesso ? 1 : 0, $tempoMs, $mensagem, $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (\Exception $e) {
            error_log("[PACS] Erro ao registrar execução do ping agendado: " . $e->getMessage());
        }

        echo json_encode(['success' => $sucesso, 'message' => $mensagem, 'tempo_ms' => $tempoMs]);
        exit;
    }

    // ── Endpoint público: status do Orthanc para o badge de login ──
    public function pingPublic(): void {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');

        // Orthanc configurado diretamente (sem tenant) para o badge público
        $orthanc = new OrthancService(
            'http://46.225.51.122:8042',
            null, null, 5
        );

        $ping = $orthanc->ping();

        if ($ping['success']) {
            $total = $orthanc->countStudies();
            echo json_encode([
                'online'        => true,
                'total_studies' => $total,
                'version'       => $ping['data']['Version'] ?? null,
            ]);
        } else {
            echo json_encode(['online' => false]);
        }
        exit;
    }

    // ── Listagem de conexões PACS (autenticado) ──
    public function index(): void {
        $this->view('servidor/index', ['title' => 'Servidores PACS — VOXEL PACS']);
    }

    public function create(): void {
        $this->view('servidor/form', ['title' => 'Nova Conexão PACS']);
    }

    public function store(): void {
        $this->redirect('/pacs');
    }

    public function edit(int $id): void {
        $this->view('servidor/form', ['title' => 'Editar Conexão PACS', 'id' => $id]);
    }

    public function update(int $id): void {
        $this->redirect('/pacs');
    }

    public function sincronizar(int $id): void {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    public function testar(int $id): void {
        header('Content-Type: application/json');
        $orthanc = new OrthancService('http://46.225.51.122:8042', null, null, 5);
        $result  = $orthanc->ping();
        echo json_encode($result);
        exit;
    }

    public function deletar(int $id): void {
        $this->redirect('/pacs');
    }
}
