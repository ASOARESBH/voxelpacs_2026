<?php
namespace App\Services;

use Dompdf\Dompdf;

/** Gera o kit técnico VPN-only sem material secreto ou endpoint interno do Orthanc. */
final class TenantVpnKitPdfService
{
    /** @param array<string,mixed> $operation */
    public function stream(array $operation, string $tenantName): void
    {
        $pdf = new Dompdf(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true]);
        $pdf->loadHtml($this->html($operation, $tenantName), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $filename = 'voxelpacs-vpn-only-' . preg_replace('/[^a-z0-9-]+/i', '-', (string) $operation['route_key']) . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf->output()));
        header('Cache-Control: no-store, private');
        echo $pdf->output();
    }

    /** @param array<string,mixed> $operation */
    private function html(array $operation, string $tenantName): string
    {
        $e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $endpoint = (string) (getenv('TENANT_WG_PUBLIC_ENDPOINT') ?: '2.28.60.214:51820');
        if (!preg_match('/^[a-zA-Z0-9.:-]{3,255}$/', $endpoint)) {
            $endpoint = '2.28.60.214:51820';
        }
        $clientConfig = "[Interface]\n"
            . "# Gere a PrivateKey localmente no cliente WireGuard; não a envie à VOXEL.\n"
            . "Address = " . $operation['vpn_client_ip'] . "/32\n\n"
            . "[Peer]\n"
            . "PublicKey = " . $operation['gateway_public_key'] . "\n"
            . "Endpoint = " . $endpoint . "\n"
            . "AllowedIPs = 10.200.10.1/32\n"
            . "PersistentKeepalive = 25\n";
        return '<!doctype html><html><head><meta charset="utf-8"><style>
            body{font-family:DejaVu Sans,sans-serif;color:#172033;font-size:10px;line-height:1.45;margin:28px}
            h1{font-size:20px;color:#0c4a6e;margin:0 0 6px}h2{font-size:13px;color:#0c4a6e;border-bottom:1px solid #bae6fd;padding-bottom:4px;margin-top:18px}
            table{border-collapse:collapse;width:100%;margin:8px 0}th,td{border:1px solid #cbd5e1;padding:6px;text-align:left;vertical-align:top}th{background:#e0f2fe;width:36%}
            pre{white-space:pre-wrap;background:#f1f5f9;border:1px solid #cbd5e1;padding:10px;font-size:8px}.notice{background:#fff7ed;border-left:4px solid #f97316;padding:8px}.ok{background:#ecfdf5;border-left:4px solid #059669;padding:8px}
            </style></head><body>
            <h1>Kit de integração DICOM — VPN-only</h1>
            <p>Tenant: <strong>' . $e($tenantName) . '</strong> &nbsp; | &nbsp; Chave de rota: <strong>' . $e($operation['route_key']) . '</strong> &nbsp; | &nbsp; Perfil: <strong>VPN obrigatória, sem TLS DICOM</strong></p>
            <div class="notice"><strong>Segurança:</strong> este documento não contém chave privada WireGuard, senha, token, endpoint interno do Orthanc, IP de backend nem dados clínicos. A chave privada deve ser gerada e mantida exclusivamente no ambiente do cliente.</div>
            <h2>1. Configuração WireGuard no cliente</h2>
            <p>Crie um túnel WireGuard local, gere a chave pública e informe-a na tela VOXEL antes de ativar o peer. Após a liberação, use a configuração abaixo, preservando a <code>PrivateKey</code> gerada no cliente.</p>
            <pre>' . $e($clientConfig) . '</pre>
            <h2>2. Destino DICOM a cadastrar no PACS/modalidade</h2>
            <table><tr><th>Campo</th><th>Valor</th></tr>
            <tr><td>Host DICOM VOXEL</td><td>10.200.10.1</td></tr>
            <tr><td>Porta DICOM</td><td>4242</td></tr>
            <tr><td>Called AE (destino)</td><td>' . $e($operation['called_ae']) . '</td></tr>
            <tr><td>Calling AE (origem autorizado)</td><td>' . $e($operation['calling_ae']) . '</td></tr>
            <tr><td>Serviços em homologação</td><td>Apenas C-ECHO</td></tr>
            <tr><td>Serviços após liberação explícita</td><td>C-ECHO e C-STORE</td></tr></table>
            <h2>3. Critério de homologação</h2>
            <div class="ok">Com o túnel ativo, configure o destino acima e execute apenas um <strong>C-ECHO</strong>. Retorne à tela do servidor VOXEL e acione <strong>Verificar C-ECHO</strong>. O registro só será marcado como validado quando o gateway encontrar associação aceita com o IP VPN, Calling AE e Called AE exatos.</div>
            <p>Não envie estudo clínico como teste. A liberação de C-STORE e a ativação de backup clínico são etapas separadas, visíveis e confirmadas na plataforma.</p>
            <h2>4. Suporte técnico</h2>
            <p>Ao solicitar suporte, informe somente a chave de rota <strong>' . $e($operation['route_key']) . '</strong>, o horário aproximado do C-ECHO e o código de erro exibido pelo seu PACS. Não envie imagem, UID, nome de paciente, senha ou chave privada.</p>
            </body></html>';
    }
}
