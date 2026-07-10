<?php
namespace App\Controllers\Auth;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;

/**
 * AccessTokenController
 * Gerencia o fluxo de criação de senha via token de acesso seguro.
 * Etapa 4 do Módulo Negócios — VOXEL PACS
 */
class AccessTokenController extends Controller
{
    /**
     * Exibe o formulário de criação de senha via token.
     * Rota pública: GET /acesso/criar-senha/{token}
     */
    public function formCriarSenha(string $token): void
    {
        $pdo   = Database::getInstance();
        $token = preg_replace('/[^a-zA-Z0-9]/', '', $token);

        $stmt = $pdo->prepare("
            SELECT t.*, u.name as user_name, u.email as user_email
            FROM bi_tenant_access_tokens t
            JOIN bi_users u ON u.id = t.user_id
            WHERE t.token = :token
              AND t.usado  = 0
              AND t.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([':token' => $token]);
        $tokenData = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tokenData) {
            $this->render('auth/token_invalido', [
                'title' => 'Link Inválido ou Expirado',
            ]);
            return;
        }

        $this->render('auth/criar_senha', [
            'title'     => 'Criar Senha de Acesso',
            'token'     => $token,
            'tokenData' => $tokenData,
        ]);
    }

    /**
     * Processa a criação de senha via token.
     * Rota pública: POST /acesso/criar-senha/{token}
     */
    public function salvarSenha(string $token): void
    {
        $pdo   = Database::getInstance();
        $token = preg_replace('/[^a-zA-Z0-9]/', '', $token);

        try {
            // Busca token válido
            $stmt = $pdo->prepare("
                SELECT t.*, u.id as user_id, u.email as user_email
                FROM bi_tenant_access_tokens t
                JOIN bi_users u ON u.id = t.user_id
                WHERE t.token = :token
                  AND t.usado  = 0
                  AND t.expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([':token' => $token]);
            $tokenData = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$tokenData) {
                $_SESSION['error'] = 'Token inválido ou expirado. Solicite um novo link.';
                $this->redirect('/acesso/criar-senha/' . $token);
                return;
            }

            $senha    = $_POST['senha']    ?? '';
            $confirma = $_POST['confirma'] ?? '';

            // Validações
            if (strlen($senha) < 8) {
                $_SESSION['error'] = 'A senha deve ter pelo menos 8 caracteres.';
                $this->redirect('/acesso/criar-senha/' . $token);
                return;
            }
            if ($senha !== $confirma) {
                $_SESSION['error'] = 'As senhas não conferem.';
                $this->redirect('/acesso/criar-senha/' . $token);
                return;
            }

            $pdo->beginTransaction();

            // Atualiza senha com Argon2id
            $hash = password_hash($senha, PASSWORD_ARGON2ID);
            $pdo->prepare("UPDATE bi_users SET password = ?, status = 'ativo', updated_at = NOW() WHERE id = ?")
                ->execute([$hash, $tokenData['user_id']]);

            // Marca token como usado
            $pdo->prepare("UPDATE bi_tenant_access_tokens SET usado = 1 WHERE token = ?")
                ->execute([$token]);

            // Log de auditoria
            Logger::info("[AccessToken] Senha criada via token para user_id={$tokenData['user_id']} email={$tokenData['user_email']} tenant_id={$tokenData['tenant_id']}");

            $pdo->commit();

            $_SESSION['success'] = 'Senha criada com sucesso! Você já pode fazer login.';
            $this->redirect('/login');

        } catch (\Throwable $e) {
            if (isset($pdo)) { try { $pdo->rollBack(); } catch (\Throwable $rb) {} }
            Logger::error("[AccessToken::salvarSenha] " . $e->getMessage());
            $_SESSION['error'] = 'Erro ao salvar senha. Tente novamente.';
            $this->redirect('/acesso/criar-senha/' . $token);
        }
    }
}
