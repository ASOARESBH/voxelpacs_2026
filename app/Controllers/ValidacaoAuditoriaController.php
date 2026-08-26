<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Services\RelatorioAuditoriaEmissaoService;

final class ValidacaoAuditoriaController extends Controller
{
    public function verificar(string $token): void
    {
        $resultado = (new RelatorioAuditoriaEmissaoService())->validar($token);
        $statusHttp = match ($resultado['status'] ?? 'invalido') {
            'valido' => 200,
            'expirado' => 410,
            'integridade_invalida' => 409,
            default => 404,
        };
        http_response_code($statusHttp);
        $this->view('public/validar_auditoria', ['resultado' => $resultado], 'public');
    }
}
