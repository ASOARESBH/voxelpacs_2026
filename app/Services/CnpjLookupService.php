<?php
/**
 * CnpjLookupService — Serviço isolado e reutilizável de consulta de CNPJ
 *
 * Interface pública para uso em outros módulos:
 * ─────────────────────────────────────────────
 *   $svc    = new CnpjLookupService();
 *   $result = $svc->lookup('12345678000195');   // apenas dígitos
 *
 *   if ($result['ok']) {
 *       $data = $result['data'];
 *       // $data['razao_social']       string
 *       // $data['nome_fantasia']      string
 *       // $data['logradouro']         string
 *       // $data['numero']             string
 *       // $data['complemento']        string
 *       // $data['bairro']             string
 *       // $data['cidade']             string
 *       // $data['uf']                 string (2 chars)
 *       // $data['cep']                string (apenas dígitos, 8 chars)
 *       // $data['situacao_cadastral'] string
 *       // $data['fonte_utilizada']    string ('brasilapi'|'receitaws'|'opencnpj')
 *   } else {
 *       $msg = $result['msg'];  // mensagem de erro para o usuário
 *   }
 *
 * Ordem de fallback: BrasilAPI → ReceitaWS → OpenCNPJ
 * Timeout por tentativa: 5 segundos
 * Validação: formato + dígito verificador ANTES de qualquer chamada externa
 *
 * Rate limits confirmados (documentação oficial, jul/2026):
 *   BrasilAPI  — sem rate limit público documentado (uso justo)
 *   ReceitaWS  — 3 req/min no plano free (https://receitaws.com.br/api)
 *   OpenCNPJ   — ~100 req/min (https://opencnpj.org/docs)
 *
 * @package App\Services
 */

namespace App\Services;

class CnpjLookupService
{
    private const TIMEOUT = 5; // segundos por tentativa

    // ─────────────────────────────────────────────────────────────────────────
    // Método principal — ponto de entrada único
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Consulta dados de um CNPJ com fallback automático entre 3 APIs públicas.
     *
     * @param  string $cnpj  CNPJ com ou sem formatação (pontos, barras, hífens)
     * @return array{ok: bool, data?: array, msg?: string}
     */
    public function lookup(string $cnpj): array
    {
        // 1. Limpar e validar
        $cnpj = preg_replace('/\D/', '', $cnpj);

        if (!$this->validarCnpj($cnpj)) {
            return [
                'ok'  => false,
                'msg' => 'CNPJ inválido. Verifique o número e tente novamente.',
            ];
        }

        // 2. Tentar APIs em ordem de fallback
        $tentativas = [
            ['nome' => 'brasilapi', 'fn' => [$this, 'consultarBrasilApi']],
            ['nome' => 'receitaws', 'fn' => [$this, 'consultarReceitaWS']],
            ['nome' => 'opencnpj',  'fn' => [$this, 'consultarOpenCnpj']],
        ];

        $erros = [];
        foreach ($tentativas as $t) {
            try {
                $raw = call_user_func($t['fn'], $cnpj);
                if ($raw !== null) {
                    $data = $this->normalizar($raw, $t['nome']);
                    if (!empty($data['razao_social'])) {
                        return ['ok' => true, 'data' => $data];
                    }
                }
                $erros[] = $t['nome'] . ': sem dados';
            } catch (\Throwable $e) {
                $erros[] = $t['nome'] . ': ' . $e->getMessage();
            }
        }

        return [
            'ok'  => false,
            'msg' => 'Não foi possível buscar os dados deste CNPJ automaticamente. Preencha manualmente.',
            'debug' => $erros,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Validação de CNPJ (formato + dígito verificador)
    // ─────────────────────────────────────────────────────────────────────────

    public function validarCnpj(string $cnpj): bool
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);

        if (strlen($cnpj) !== 14) return false;
        if (preg_match('/^(\d)\1{13}$/', $cnpj)) return false; // todos iguais

        // Primeiro dígito verificador
        $soma = 0;
        $peso = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        for ($i = 0; $i < 12; $i++) {
            $soma += (int)$cnpj[$i] * $peso[$i];
        }
        $resto = $soma % 11;
        $d1    = $resto < 2 ? 0 : 11 - $resto;
        if ((int)$cnpj[12] !== $d1) return false;

        // Segundo dígito verificador
        $soma = 0;
        $peso = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        for ($i = 0; $i < 13; $i++) {
            $soma += (int)$cnpj[$i] * $peso[$i];
        }
        $resto = $soma % 11;
        $d2    = $resto < 2 ? 0 : 11 - $resto;
        if ((int)$cnpj[13] !== $d2) return false;

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // API 1: BrasilAPI (gratuita, sem autenticação)
    // GET https://brasilapi.com.br/api/cnpj/v1/{cnpj}
    // ─────────────────────────────────────────────────────────────────────────

    private function consultarBrasilApi(string $cnpj): ?array
    {
        $url  = "https://brasilapi.com.br/api/cnpj/v1/{$cnpj}";
        $json = $this->httpGet($url);
        if (!$json) return null;

        $data = json_decode($json, true);
        if (!is_array($data) || isset($data['message'])) return null;

        return ['_fonte' => 'brasilapi', '_raw' => $data];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // API 2: ReceitaWS (gratuita, rate limit ~3 req/min no plano free)
    // GET https://www.receitaws.com.br/v1/cnpj/{cnpj}
    // ─────────────────────────────────────────────────────────────────────────

    private function consultarReceitaWS(string $cnpj): ?array
    {
        $url  = "https://www.receitaws.com.br/v1/cnpj/{$cnpj}";
        $json = $this->httpGet($url);
        if (!$json) return null;

        $data = json_decode($json, true);
        if (!is_array($data) || ($data['status'] ?? '') === 'ERROR') return null;

        return ['_fonte' => 'receitaws', '_raw' => $data];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // API 3: OpenCNPJ (gratuita, ~100 req/min)
    // GET https://api.opencnpj.org/{cnpj}
    // ─────────────────────────────────────────────────────────────────────────

    private function consultarOpenCnpj(string $cnpj): ?array
    {
        $url  = "https://api.opencnpj.org/{$cnpj}";
        $json = $this->httpGet($url);
        if (!$json) return null;

        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['razao_social'] ?? $data['nome'] ?? '')) return null;

        return ['_fonte' => 'opencnpj', '_raw' => $data];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Normalização — converte resposta de qualquer API para formato interno
    // ─────────────────────────────────────────────────────────────────────────

    private function normalizar(array $envelope, string $fonte): array
    {
        $r = $envelope['_raw'];

        switch ($fonte) {
            case 'brasilapi':
                return [
                    'razao_social'       => $this->str($r['razao_social']       ?? ''),
                    'nome_fantasia'      => $this->str($r['nome_fantasia']       ?? ''),
                    'logradouro'         => $this->str($r['logradouro']          ?? ''),
                    'numero'             => $this->str($r['numero']              ?? ''),
                    'complemento'        => $this->str($r['complemento']         ?? ''),
                    'bairro'             => $this->str($r['bairro']              ?? ''),
                    'cidade'             => $this->str($r['municipio']           ?? ''),
                    'uf'                 => strtoupper($this->str($r['uf']       ?? '')),
                    'cep'                => preg_replace('/\D/', '', $r['cep']   ?? ''),
                    'situacao_cadastral' => $this->str($r['descricao_situacao_cadastral'] ?? ''),
                    'fonte_utilizada'    => 'brasilapi',
                ];

            case 'receitaws':
                return [
                    'razao_social'       => $this->str($r['nome']               ?? ''),
                    'nome_fantasia'      => $this->str($r['fantasia']            ?? ''),
                    'logradouro'         => $this->str($r['logradouro']          ?? ''),
                    'numero'             => $this->str($r['numero']              ?? ''),
                    'complemento'        => $this->str($r['complemento']         ?? ''),
                    'bairro'             => $this->str($r['bairro']              ?? ''),
                    'cidade'             => $this->str($r['municipio']           ?? ''),
                    'uf'                 => strtoupper($this->str($r['uf']       ?? '')),
                    'cep'                => preg_replace('/\D/', '', $r['cep']   ?? ''),
                    'situacao_cadastral' => $this->str($r['situacao']            ?? ''),
                    'fonte_utilizada'    => 'receitaws',
                ];

            case 'opencnpj':
                // OpenCNPJ pode retornar estrutura variável — normalizar defensivamente
                $endereco = $r['estabelecimento']['tipo_logradouro'] ?? '';
                return [
                    'razao_social'       => $this->str($r['razao_social']        ?? $r['nome'] ?? ''),
                    'nome_fantasia'      => $this->str($r['estabelecimento']['nome_fantasia'] ?? $r['nome_fantasia'] ?? ''),
                    'logradouro'         => trim($endereco . ' ' . $this->str($r['estabelecimento']['logradouro'] ?? $r['logradouro'] ?? '')),
                    'numero'             => $this->str($r['estabelecimento']['numero']        ?? $r['numero']       ?? ''),
                    'complemento'        => $this->str($r['estabelecimento']['complemento']   ?? $r['complemento']  ?? ''),
                    'bairro'             => $this->str($r['estabelecimento']['bairro']        ?? $r['bairro']       ?? ''),
                    'cidade'             => $this->str($r['estabelecimento']['cidade']['nome'] ?? $r['municipio']   ?? ''),
                    'uf'                 => strtoupper($this->str($r['estabelecimento']['estado']['sigla'] ?? $r['uf'] ?? '')),
                    'cep'                => preg_replace('/\D/', '', $r['estabelecimento']['cep'] ?? $r['cep'] ?? ''),
                    'situacao_cadastral' => $this->str($r['estabelecimento']['situacao_cadastral'] ?? ''),
                    'fonte_utilizada'    => 'opencnpj',
                ];

            default:
                return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** HTTP GET com timeout curto via cURL */
    private function httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
            CURLOPT_USERAGENT      => 'VoxelPACS/2026 (+https://voxelpacs.com.br)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 300) return null;

        return $body;
    }

    /** Limpar string: trim + converter encoding para UTF-8 se necessário */
    private function str(string $v): string
    {
        $v = trim($v);
        if (!mb_check_encoding($v, 'UTF-8')) {
            $v = mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1');
        }
        return $v;
    }
}
