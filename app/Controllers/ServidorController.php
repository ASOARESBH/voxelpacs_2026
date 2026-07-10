<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\TenantContext;
use App\Core\Database;
use App\Services\OrthancService;

class ServidorController extends Controller {
    
    public function index(): void {
        $tenantId = TenantContext::id();
        
        $db = Database::getInstance();
        $servidores = $db->fetchAll("SELECT * FROM bi_orthanc_servidores WHERE tenant_id = ?", [$tenantId]);
        
        $this->view('servidor/index', [
            'title' => 'Servidores Orthanc',
            'servidores' => $servidores
        ]);
    }

    public function create(): void {
        $this->view('servidor/form', [
            'title' => 'Novo Servidor Orthanc',
            'servidor' => null
        ]);
    }

    public function store(): void {
        $tenantId = TenantContext::id();
        $db = Database::getInstance();
        
        $db->executeWrite("
            INSERT INTO bi_orthanc_servidores (tenant_id, nome, url, usuario, senha, timeout, ativo)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ", [
            $tenantId,
            $_POST['nome'] ?? 'Orthanc Principal',
            $_POST['url'] ?? '',
            $_POST['usuario'] ?: null,
            $_POST['senha'] ?: null,
            (int)($_POST['timeout'] ?? 30),
            isset($_POST['ativo']) ? 1 : 0
        ]);
        
        $this->redirect('/servidor');
    }

    public function edit(int $id): void {
        $tenantId = TenantContext::id();
        $db = Database::getInstance();
        
        $servidor = $db->fetchOne("SELECT * FROM bi_orthanc_servidores WHERE id = ? AND tenant_id = ?", [$id, $tenantId]);
        
        if (!$servidor) {
            $this->redirect('/servidor');
        }
        
        $this->view('servidor/form', [
            'title' => 'Editar Servidor Orthanc',
            'servidor' => $servidor
        ]);
    }

    public function update(int $id): void {
        $tenantId = TenantContext::id();
        $db = Database::getInstance();
        
        $params = [
            $_POST['nome'] ?? '',
            $_POST['url'] ?? '',
            $_POST['usuario'] ?: null,
            (int)($_POST['timeout'] ?? 30),
            isset($_POST['ativo']) ? 1 : 0,
            $id,
            $tenantId
        ];
        
        $sql = "UPDATE bi_orthanc_servidores SET nome=?, url=?, usuario=?, timeout=?, ativo=? ";
        
        if (!empty($_POST['senha'])) {
            $sql .= ", senha=? ";
            array_splice($params, 5, 0, [$_POST['senha']]);
        }
        
        $sql .= " WHERE id=? AND tenant_id=?";
        
        $db->executeWrite($sql, $params);
        
        $this->redirect('/servidor');
    }

    public function testar(int $id): void {
        $tenantId = TenantContext::id();
        $db = Database::getInstance();
        
        $servidor = $db->fetchOne("SELECT * FROM bi_orthanc_servidores WHERE id = ? AND tenant_id = ?", [$id, $tenantId]);
        
        if (!$servidor) {
            echo json_encode(['success' => false, 'message' => 'Servidor não encontrado']);
            return;
        }
        
        $orthanc = new OrthancService($servidor['url'], $servidor['usuario'], $servidor['senha'], $servidor['timeout']);
        $ping = $orthanc->ping();
        
        if ($ping['success']) {
            $version = $ping['data']['Version'] ?? 'Desconhecida';
            $name = $ping['data']['Name'] ?? 'Orthanc';
            
            // Tenta contar estudos para mostrar no teste
            $totalEstudos = $orthanc->countStudies();
            
            $db->executeWrite("
                UPDATE bi_orthanc_servidores 
                SET ultimo_ping = NOW(), status_ping = 'online', versao = ? 
                WHERE id = ?
            ", [$version, $id]);
            
            echo json_encode([
                'success' => true, 
                'message' => "Conexão bem-sucedida! Orthanc $name (v$version). Total de estudos: $totalEstudos"
            ]);
        } else {
            $db->executeWrite("
                UPDATE bi_orthanc_servidores 
                SET status_ping = 'erro', observacoes = ? 
                WHERE id = ?
            ", [$ping['error'], $id]);
            
            echo json_encode([
                'success' => false, 
                'message' => "Falha na conexão: " . $ping['error']
            ]);
        }
    }
}
