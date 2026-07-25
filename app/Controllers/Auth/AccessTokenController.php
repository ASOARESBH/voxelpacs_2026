<?php
namespace App\Controllers\Auth;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Mailer;

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
            // 'none' = sem layout: estas views são páginas HTML autônomas
            // (têm seu próprio <html>), não fragmentos para header/footer.
            $this->view('auth/token_invalido', [
                'title' => 'Link Inválido ou Expirado',
            ], 'none');
            return;
        }

        $this->view('auth/criar_senha', [
            'title'     => 'Criar Senha de Acesso',
            'token'     => $token,
            'tokenData' => $tokenData,
        ], 'none');
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

    /**
     * Exibe o formulário "Esqueceu a senha?" (pede apenas o e-mail).
     * Rota pública: GET /esqueci-senha
     */
    public function formEsqueciSenha(): void
    {
        $this->view('auth/esqueci_senha', [
            'title' => 'Esqueci minha senha',
        ], 'auth');
    }

    /**
     * Processa o pedido de redefinição de senha.
     * Rota pública: POST /esqueci-senha
     *
     * Por critério de segurança, a resposta é SEMPRE a mesma mensagem
     * genérica, exista ou não uma conta com o e-mail informado — nunca
     * revela se um e-mail está ou não cadastrado. O e-mail com o link só é
     * realmente enviado quando existe conta ativa e vinculada a um tenant.
     * Reaproveita 100% a tabela bi_tenant_access_tokens e o fluxo
     * /acesso/criar-senha/{token} já usados para primeiro acesso — o campo
     * `tipo` só distingue a origem do token para fins de auditoria; a
     * validação em salvarSenha() não depende dele.
     */
    public function enviarLinkRedefinicao(): void
    {
        $email = trim($_POST['email'] ?? '');

        // Mensagem sempre idêntica, independentemente do resultado interno.
        $mensagemGenerica = 'Se este e-mail estiver cadastrado em nossa base, '
            . 'enviamos um link para redefinição de senha. Verifique também a caixa de spam.';

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->view('auth/esqueci_senha', [
                'title'   => 'Esqueci minha senha',
                'sucesso' => $mensagemGenerica,
            ], 'auth');
            return;
        }

        try {
            $pdo = Database::getInstance();

            $stmt = $pdo->prepare("SELECT id, name FROM bi_users WHERE email = :email AND status = 'ativo' LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($user) {
                // bi_tenant_access_tokens.tenant_id é NOT NULL — precisa de um
                // tenant vinculado ao usuário (superadmin sem nenhum vínculo
                // não consegue gerar o token; ver nota na documentação).
                $stmtTenant = $pdo->prepare("
                    SELECT tenant_id FROM bi_user_tenants
                    WHERE user_id = :uid AND ativo = 1
                    ORDER BY id ASC LIMIT 1
                ");
                $stmtTenant->execute([':uid' => $user['id']]);
                $tenantId = $stmtTenant->fetchColumn();

                if ($tenantId) {
                    // Evita reenvio em rajada: reaproveita token válido gerado
                    // há menos de 2 minutos em vez de criar um novo a cada clique.
                    $stmtRecente = $pdo->prepare("
                        SELECT token FROM bi_tenant_access_tokens
                        WHERE user_id = :uid AND tipo = 'redefinir_senha'
                          AND usado = 0 AND expires_at > NOW()
                          AND created_at > (NOW() - INTERVAL 2 MINUTE)
                        ORDER BY id DESC LIMIT 1
                    ");
                    $stmtRecente->execute([':uid' => $user['id']]);
                    $token = $stmtRecente->fetchColumn();

                    if (!$token) {
                        $token = bin2hex(random_bytes(32));
                        $pdo->prepare("
                            INSERT INTO bi_tenant_access_tokens
                                (user_id, tenant_id, token, tipo, usado, expires_at)
                            VALUES (:user_id, :tenant_id, :token, 'redefinir_senha', 0, :expires_at)
                        ")->execute([
                            ':user_id'    => $user['id'],
                            ':tenant_id'  => $tenantId,
                            ':token'      => $token,
                            ':expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour')),
                        ]);
                    }

                    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'voxelpacs.com.br');
                    $link    = $baseUrl . '/acesso/criar-senha/' . $token;

                    $html = '<p>Olá, ' . htmlspecialchars($user['name']) . '!</p>'
                        . '<p>Recebemos um pedido de redefinição de senha para sua conta no VOXEL PACS.</p>'
                        . '<p><a href="' . htmlspecialchars($link) . '">Clique aqui para criar uma nova senha</a></p>'
                        . '<p style="color:#64748b;font-size:.85rem;">Este link expira em 1 hora e só pode ser usado uma vez. '
                        . 'Se você não solicitou isso, apenas ignore este e-mail — sua senha atual continua válida.</p>';

                    Mailer::send($email, 'Redefinição de senha — VOXEL PACS', $html);
                    Logger::info("[AccessToken::enviarLinkRedefinicao] Link enviado para user_id={$user['id']}");
                } else {
                    // Conta existe mas sem tenant vinculado (ex: superadmin
                    // puro) — não há como gerar o token (FK obrigatória).
                    // Não revela isso ao usuário; só registra internamente.
                    Logger::warning("[AccessToken::enviarLinkRedefinicao] user_id={$user['id']} sem tenant vinculado — token não gerado");
                }
            }
        } catch (\Throwable $e) {
            Logger::error('[AccessToken::enviarLinkRedefinicao] ' . $e->getMessage());
        }

        $this->view('auth/esqueci_senha', [
            'title'   => 'Esqueci minha senha',
            'sucesso' => $mensagemGenerica,
        ], 'auth');
    }
}
