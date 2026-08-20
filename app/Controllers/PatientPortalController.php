<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Logger;
use App\Core\PatientPortalSession;
use App\Services\PatientPortalService;

final class PatientPortalController extends Controller
{
    private const GENERIC_FAILURE = 'Não foi possível confirmar seus dados. Verifique as informações e tente novamente.';

    public function home(): void
    {
        if ((new PatientPortalService())->activeScope($this->ip()) !== null) {
            $this->redirect('/resultados');
            return;
        }
        $this->view('portal/login', ['csrf' => $this->csrfToken()], 'portal');
    }

    public function identify(): void
    {
        if (!$this->validCsrf((string) ($_POST['csrf'] ?? ''))) {
            $this->renderGenericFailure();
            return;
        }
        try {
            $result = (new PatientPortalService())->identify(
                (string) ($_POST['nome_completo'] ?? ''),
                (string) ($_POST['data_nascimento'] ?? ''),
                (string) ($_POST['sexo'] ?? ''),
                $this->ip(),
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
            );
            if (!$result['ok']) { $this->renderGenericFailure(); return; }
            $this->view('portal/challenge', [
                'csrf' => $this->csrfToken(),
                'challengeToken' => (string) $result['challenge_token'],
                'options' => $result['options'] ?? [],
            ], 'portal');
        } catch (\Throwable $e) {
            Logger::error('PatientPortalController::identify falhou', ['error' => $e->getMessage(), 'ip' => $this->ip()]);
            $this->renderGenericFailure();
        }
    }

    public function verifyInstitution(): void
    {
        if (!$this->validCsrf((string) ($_POST['csrf'] ?? ''))) {
            $this->renderGenericFailure();
            return;
        }
        try {
            $result = (new PatientPortalService())->verifyInstitution(
                (string) ($_POST['challenge_token'] ?? ''),
                (string) ($_POST['institution_name'] ?? ''),
                $this->ip(),
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
            );
            if (!$result['ok']) { $this->renderGenericFailure(); return; }
            $this->redirect('/resultados');
        } catch (\Throwable $e) {
            Logger::error('PatientPortalController::verifyInstitution falhou', ['error' => $e->getMessage(), 'ip' => $this->ip()]);
            $this->renderGenericFailure();
        }
    }

    public function results(): void
    {
        $service = new PatientPortalService();
        $scope = $service->activeScope($this->ip());
        if ($scope === null) { $this->redirect('/'); return; }
        $this->view('portal/results', [
            'studies' => $service->studies($scope),
            'patientName' => $this->displayName((string) $scope['patient_name_normalized']),
            'csrf' => $this->csrfToken(),
        ], 'portal');
    }

    public function pdf(string $token): void
    {
        $service = new PatientPortalService();
        $scope = $service->activeScope($this->ip());
        if ($scope === null || !$service->releasedReportByToken($token, $scope)) {
            http_response_code(404);
            echo 'Laudo não encontrado.';
            return;
        }
        // O renderer interno preserva layout, assinatura e auditoria. Ele recebe
        // o escopo de paciente através de sessão e valida o mesmo token novamente.
        $_GET['portal_patient_token'] = $token;
        (new ReportsController())->pdfByToken($token);
    }

    public function logout(): void
    {
        (new PatientPortalService())->logout();
        $this->redirect('/');
    }

    private function validCsrf(string $token): bool
    {
        return $token !== '' && !empty($_SESSION['csrf_token']) && hash_equals((string) $_SESSION['csrf_token'], $token);
    }

    private function renderGenericFailure(): void
    {
        $this->view('portal/login', ['csrf' => $this->csrfToken(), 'error' => self::GENERIC_FAILURE], 'portal');
    }

    private function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }

    private function displayName(string $normalized): string
    {
        return mb_convert_case(mb_strtolower($normalized, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }
}
