<?php

namespace App\Models;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Model;
use App\Core\SqlHelper;
use PDO;

/**
 * Configuração global dos conectores de comunicação da plataforma.
 *
 * O model nunca retorna segredos decriptados à interface. Os serviços de
 * integração usam segredoDecriptado() exclusivamente no momento da chamada.
 */
class ConectorConfig extends Model
{
    protected string $table = 'bi_conectores_config';
    protected bool $hasTenant = false;

    /** @var array<int, string> */
    private const TIPOS = ['whatsapp', 'telegram'];

    /** @var array<int, string> */
    private const CAMPOS_WHATSAPP = [
        'ativo', 'evolution_api_url', 'evolution_api_key', 'evolution_instance', 'whatsapp_destino',
    ];

    /** @var array<int, string> */
    private const CAMPOS_TELEGRAM = [
        'ativo', 'telegram_bot_token', 'telegram_chat_id',
    ];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    /** @return array<string, mixed>|null */
    public function findByTipo(string $tipo): ?array
    {
        $this->assertTipo($tipo);
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE tipo = ? LIMIT 1");
        $stmt->execute([$tipo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Salva campos permitidos sem sobrescrever uma credencial já existente
     * quando o superadmin mantém o campo de senha em branco.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(string $tipo, array $data): array
    {
        $this->assertTipo($tipo);
        $existente = $this->findByTipo($tipo) ?? ['tipo' => $tipo];
        $permitidos = $tipo === 'whatsapp' ? self::CAMPOS_WHATSAPP : self::CAMPOS_TELEGRAM;
        $campos = [];
        $params = [];

        foreach ($permitidos as $campo) {
            if (!array_key_exists($campo, $data)) {
                continue;
            }

            $valor = $data[$campo];
            if (in_array($campo, ['evolution_api_key', 'telegram_bot_token'], true)) {
                $valor = trim((string) $valor);
                if ($valor === '') {
                    continue;
                }
                $valor = Crypto::encrypt($valor);
            }

            $campos[] = "{$campo} = ?";
            $params[] = $valor;
        }

        if ($campos === []) {
            return $existente;
        }

        $campos[] = 'updated_at = NOW()';
        $params[] = $tipo;
        $sql = "UPDATE {$this->table} SET " . implode(', ', $campos) . ' WHERE tipo = ?';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->findByTipo($tipo) ?? $existente;
    }

    public function updateTeste(string $tipo, bool $ok, string $mensagem): void
    {
        $this->assertTipo($tipo);
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table}
             SET ultimo_teste_em = NOW(), ultimo_teste_status = ?, ultimo_teste_mensagem = ?, updated_at = NOW()
             WHERE tipo = ?"
        );
        $stmt->execute([$ok ? 'sucesso' : 'erro', self::truncar($mensagem, 500), $tipo]);
    }

    public function segredoDecriptado(?string $valor): ?string
    {
        return Crypto::decrypt($valor);
    }

    /** @return array<string, mixed> */
    public function paraView(string $tipo): array
    {
        $config = $this->findByTipo($tipo) ?? ['tipo' => $tipo, 'ativo' => false];
        $config['tem_evolution_api_key'] = !empty($config['evolution_api_key']);
        $config['tem_telegram_bot_token'] = !empty($config['telegram_bot_token']);
        unset($config['evolution_api_key'], $config['telegram_bot_token']);
        return $config;
    }

    private function assertTipo(string $tipo): void
    {
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new \InvalidArgumentException('Tipo de conector inválido.');
        }
    }

    private static function truncar(string $texto, int $limite): string
    {
        return mb_substr(trim($texto), 0, $limite);
    }
}
