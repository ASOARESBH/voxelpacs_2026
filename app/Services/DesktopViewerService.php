<?php

namespace App\Services;

use App\Core\Database;

/**
 * DesktopViewerService
 *
 * Monta os launchers de visualizadores DICOM desktop (RadiAnt, Weasis) para um
 * estudo, resolvendo a configuração de conexão (host/porta/AE Title) por
 * tenant — com fallback para a configuração global do servidor PACS
 * (`bi_pacs_servidor`) quando o tenant não tiver uma config própria ativa em
 * `bi_viewer_desktop_config`.
 *
 * Também é responsável por registrar toda tentativa de abertura (sucesso,
 * negado por RBAC, ou erro) em `bi_viewer_access_log`, para auditoria e para
 * os futuros gráficos de "visualizações por viewer/médico/unidade/modalidade".
 *
 * IMPORTANTE — protocolos radiant:// e weasis://:
 * A sintaxe exata desses protocolos é definida pelos fabricantes (Medixant e
 * comunidade Weasis) e pode variar entre versões do software instalado. Os
 * métodos gerarLauncherRadiant()/gerarLauncherWeasis() abaixo implementam o
 * formato documentado publicamente até onde eu tenho certeza, mas devem ser
 * validados contra uma instalação real do RadiAnt/Weasis antes de considerar
 * esta integração 100% pronta para produção. Os pontos de ajuste estão
 * isolados nesses dois métodos — não é necessário mexer em mais nada do
 * serviço para corrigir a sintaxe.
 */
class DesktopViewerService
{
    /**
     * Resolve a configuração de conexão DICOM para um tenant+viewer.
     * Prioridade: bi_viewer_desktop_config (por tenant) > bi_pacs_servidor (global).
     *
     * @return array{host:?string,porta:?int,ae_title:?string,calling_ae:?string}|null
     *         null quando não há nenhuma fonte de configuração disponível.
     */
    public function resolverConfig(?int $tenantId, string $viewer): ?array
    {
        $pdo = Database::getInstance();

        $config = null;
        if ($tenantId) {
            $stmt = $pdo->prepare("
                SELECT host, porta, ae_title, calling_ae
                FROM bi_viewer_desktop_config
                WHERE tenant_id = :tenant_id AND viewer = :viewer AND ativo = 1
                LIMIT 1
            ");
            $stmt->execute([':tenant_id' => $tenantId, ':viewer' => $viewer]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $config = $row;
            }
        }

        // Fallback: servidor PACS global (bi_pacs_servidor), já usado pelo
        // fluxo de sincronização — não duplicamos essa configuração.
        $servidor = $pdo->query("
            SELECT url, dicom_aet, dicom_port
            FROM bi_pacs_servidor
            WHERE id = 1
            LIMIT 1
        ")->fetch(\PDO::FETCH_ASSOC);

        $hostServidor = null;
        if ($servidor && !empty($servidor['url'])) {
            $hostServidor = parse_url($servidor['url'], PHP_URL_HOST);
        }

        $host      = $config['host']       ?? $hostServidor;
        $porta     = $config['porta']      ?? ($servidor['dicom_port'] ?? null);
        $aeTitle   = $config['ae_title']   ?? ($servidor['dicom_aet']  ?? null);
        $callingAe = $config['calling_ae'] ?? (getenv('DESKTOP_VIEWER_CALLING_AE') ?: null);

        if (empty($host) || empty($aeTitle)) {
            return null;
        }

        return [
            'host'       => $host,
            'porta'      => $porta ? (int) $porta : null,
            'ae_title'   => $aeTitle,
            'calling_ae' => $callingAe,
        ];
    }

    /**
     * Monta a URI radiant:// para abrir o estudo diretamente via Query/Retrieve
     * DICOM, sem download manual de arquivos.
     *
     * Formato (integração EMR do RadiAnt — validar contra a versão instalada):
     *   radiant://?QueryRetrieveLevel=STUDY&Host=...&Port=...&AETitle=...
     *              &CallingAETitle=...&StudyInstanceUID=...&AccessionNumber=...
     */
    public function gerarLauncherRadiant(array $estudo, array $config): string
    {
        $params = [
            'QueryRetrieveLevel' => 'STUDY',
            'Host'               => $config['host'],
            'Port'               => $config['porta'],
            'AETitle'            => $config['ae_title'],
            'CallingAETitle'     => $config['calling_ae'],
            'StudyInstanceUID'   => $estudo['study_instance_uid'] ?? '',
        ];
        if (!empty($estudo['accession_number'])) {
            $params['AccessionNumber'] = $estudo['accession_number'];
        }
        if (!empty($estudo['patient_id'])) {
            $params['PatientID'] = $estudo['patient_id'];
        }

        return 'radiant://?' . http_build_query(array_filter($params, fn($v) => $v !== null && $v !== ''));
    }

    /**
     * Monta a URI weasis:// para abrir o estudo diretamente via Query/Retrieve
     * DICOM, sem download manual de arquivos.
     *
     * Formato (protocolo Weasis, argumentos equivalentes à CLI do
     * weasis-launcher — validar contra a versão instalada):
     *   weasis://$dicom:rs -h HOST -p PORT --called-aet AET --calling-aet CALLING
     *            -S StudyInstanceUID
     */
    public function gerarLauncherWeasis(array $estudo, array $config): string
    {
        $studyUid = $estudo['study_instance_uid'] ?? '';

        $args = [
            '$dicom:rs',
            '-h', $config['host'],
            '-p', (string) $config['porta'],
            '--called-aet', $config['ae_title'],
            '--calling-aet', $config['calling_ae'],
            '-S', $studyUid,
        ];

        $cmd = implode(' ', array_map(
            fn($a) => preg_match('/\s/', $a) ? '"' . $a . '"' : $a,
            array_filter($args, fn($a) => $a !== null && $a !== '')
        ));

        return 'weasis://' . rawurlencode($cmd);
    }

    /**
     * Valida se a configuração resolvida tem o mínimo necessário para montar
     * um launcher (host, porta e AE Title do PACS).
     */
    public function validarConfig(?array $config): bool
    {
        return $config !== null
            && !empty($config['host'])
            && !empty($config['porta'])
            && !empty($config['ae_title']);
    }

    /**
     * Registra uma tentativa de abertura de estudo (em qualquer viewer) para
     * auditoria e para os gráficos futuros de uso por viewer/médico/unidade.
     */
    public function registrarAcesso(array $dados): void
    {
        try {
            Database::getInstance()->prepare("
                INSERT INTO bi_viewer_access_log
                    (tenant_id, study_id, patient_id, viewer, usuario_id, ip, user_agent,
                     study_instance_uid, accession_number, opened_at, tempo_execucao_ms,
                     status, mensagem_erro)
                VALUES
                    (:tenant_id, :study_id, :patient_id, :viewer, :usuario_id, :ip, :user_agent,
                     :study_instance_uid, :accession_number, NOW(), :tempo_execucao_ms,
                     :status, :mensagem_erro)
            ")->execute([
                ':tenant_id'          => $dados['tenant_id']          ?? null,
                ':study_id'           => $dados['study_id']           ?? null,
                ':patient_id'         => $dados['patient_id']         ?? null,
                ':viewer'             => $dados['viewer'],
                ':usuario_id'         => $dados['usuario_id']         ?? null,
                ':ip'                 => $dados['ip']                 ?? null,
                ':user_agent'         => $dados['user_agent']         ?? null,
                ':study_instance_uid' => $dados['study_instance_uid'] ?? null,
                ':accession_number'   => $dados['accession_number']   ?? null,
                ':tempo_execucao_ms'  => $dados['tempo_execucao_ms']  ?? null,
                ':status'             => $dados['status'],
                ':mensagem_erro'      => $dados['mensagem_erro']      ?? null,
            ]);
        } catch (\Throwable $ex) {
            // Nunca bloquear a abertura do viewer por falha no log de auditoria.
            error_log('[DesktopViewerService::registrarAcesso] ' . $ex->getMessage());
        }
    }
}
