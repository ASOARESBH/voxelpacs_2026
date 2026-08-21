<?php

namespace App\Services;

use App\Core\Logger;

/**
 * Orquestra notificações globais administrativas após a confirmação de um laudo.
 * Falhas de conectores são deliberadamente isoladas do fluxo clínico.
 */
final class ConectorNotificacaoService
{
    /**
     * @param array<string, mixed>|object $estudo
     * @param array<string, mixed> $medico
     * @param array<string, mixed>|object $report
     */
    public static function notificarLaudoRealizado(array|object $estudo, array $medico, array|object $report, string $situacao): void
    {
        $dados = self::normalizarDados($estudo, $medico, $report, $situacao);
        $evento = $situacao === 'liberado' ? 'laudo_liberado' : 'laudo_assinado';

        try {
            $whatsApp = new WhatsAppService();
            $config = $whatsApp->config();
            if ($whatsApp->isConfigured() && $config !== null) {
                $whatsApp->enviarTexto((string) $config['whatsapp_destino'], self::mensagemWhatsApp($dados), $evento);
            }
        } catch (\Throwable $e) {
            Logger::error('[ConectorNotificacaoService] WhatsApp falhou após assinatura', [
                'evento' => $evento,
                'report_id' => $dados['report_id'],
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $telegram = new TelegramService();
            $config = $telegram->config();
            if ($telegram->isConfigured() && $config !== null) {
                $telegram->enviarTexto((string) $config['telegram_chat_id'], self::mensagemTelegram($dados), $evento, 'HTML');
            }
        } catch (\Throwable $e) {
            Logger::error('[ConectorNotificacaoService] Telegram falhou após assinatura', [
                'evento' => $evento,
                'report_id' => $dados['report_id'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<string, string|int> */
    private static function normalizarDados(array|object $estudo, array $medico, array|object $report, string $situacao): array
    {
        $estudo = (array) $estudo;
        $report = (array) $report;
        return [
            'report_id' => (int) ($report['id'] ?? 0),
            'situacao' => $situacao === 'liberado' ? 'LIBERADO' : 'ASSINADO',
            'unidade' => self::texto($estudo['institution_name'] ?? $estudo['unidade'] ?? 'Não informada'),
            'paciente' => self::texto($estudo['patient_name'] ?? $estudo['nome_paciente'] ?? 'Não informado'),
            'exame' => self::texto($estudo['study_description'] ?? $estudo['descricao_estudo'] ?? 'Sem descrição'),
            'modalidade' => self::texto($estudo['modality'] ?? $estudo['modalidade'] ?? 'Não informada'),
            'data' => self::texto($estudo['study_date'] ?? $estudo['data_estudo'] ?? date('d/m/Y')),
            'medico' => self::texto($medico['nome'] ?? $medico['name'] ?? 'Não informado'),
            'crm' => self::texto($medico['crm'] ?? ''),
        ];
    }

    /** @param array<string, string|int> $dados */
    private static function mensagemWhatsApp(array $dados): string
    {
        return "*VOXEL PACS — Laudo {$dados['situacao']}*\n"
            . "Unidade: {$dados['unidade']}\n"
            . "Paciente: {$dados['paciente']}\n"
            . "Exame: {$dados['exame']}\n"
            . "Modalidade: {$dados['modalidade']}\n"
            . "Data: {$dados['data']}\n"
            . "Médico: {$dados['medico']}" . ($dados['crm'] !== '' ? " (CRM {$dados['crm']})" : '');
    }

    /** @param array<string, string|int> $dados */
    private static function mensagemTelegram(array $dados): string
    {
        return '<b>VOXEL PACS — Laudo ' . self::html($dados['situacao']) . '</b>\n'
            . '<b>Unidade:</b> ' . self::html($dados['unidade']) . '\n'
            . '<b>Paciente:</b> ' . self::html($dados['paciente']) . '\n'
            . '<b>Exame:</b> ' . self::html($dados['exame']) . '\n'
            . '<b>Modalidade:</b> ' . self::html($dados['modalidade']) . '\n'
            . '<b>Data:</b> ' . self::html($dados['data']) . '\n'
            . '<b>Médico:</b> ' . self::html($dados['medico'])
            . ($dados['crm'] !== '' ? ' (CRM ' . self::html($dados['crm']) . ')' : '');
    }

    private static function texto(mixed $value): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', (string) $value) ?? ''), 0, 240);
    }

    private static function html(string|int $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
