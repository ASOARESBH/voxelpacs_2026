<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\PatientPortalSession;
use App\Services\PatientPortalService;
use App\Services\PortalImageSessionService;
use App\Services\PortalShareService;
use App\Services\ReportPdfService;

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

    public function images(string $token): void
    {
        $service = new PatientPortalService();
        $scope = $service->activeScope($this->ip());
        if ($scope === null || !$service->releasedReportByToken($token, $scope)) {
            http_response_code(404);
            echo 'Exame não encontrado.';
            return;
        }

        $enabled = filter_var(getenv('PORTAL_IMAGES_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
        $anonymized = filter_var(getenv('PORTAL_IMAGES_ANONYMIZED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
        if (!$enabled || !$anonymized) {
            $this->view('portal/images_unavailable', [
                'csrf' => $this->csrfToken(),
                'patientName' => $this->displayName((string) $scope['patient_name_normalized']),
            ], 'portal');
            return;
        }

        $report = $service->releasedReportByToken($token, $scope);
        if ($report === null) {
            http_response_code(404);
            echo 'Exame não encontrado.';
            return;
        }

        $imageSession = new PortalImageSessionService(Database::getInstance());
        $result = $imageSession->issueOrQueue(
            $report,
            $scope,
            $this->ip(),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );
        if (($result['status'] ?? '') !== 'ready' || empty($result['token'])) {
            $this->view('portal/images_preparing', [
                'csrf' => $this->csrfToken(),
                'patientName' => $this->displayName((string) $scope['patient_name_normalized']),
                'message' => (string) ($result['message'] ?? 'As imagens estão sendo preparadas.'),
            ], 'portal');
            return;
        }

        setcookie('voxel_portal_image_session', (string) $result['token'], [
            'expires' => time() + 15 * 60,
            'path' => '/imagens/dicom-web/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        $this->view('portal/images_viewer', [
            'csrf' => $this->csrfToken(),
            'patientName' => $this->displayName((string) $scope['patient_name_normalized']),
            'viewerUrl' => rtrim((string) (getenv('PORTAL_IMAGE_VIEWER_URL') ?: '/imagens/viewer'), '/')
                . '/viewer?StudyInstanceUIDs=' . rawurlencode((string) $result['study_uid']),
        ], 'portal');
    }

    public function share(string $token): void
    {
        if (!$this->validCsrf((string) ($_POST['csrf'] ?? ''))) {
            $this->json(['ok' => false, 'msg' => 'Sessão inválida. Atualize a página e tente novamente.'], 403);
            return;
        }
        $portal = new PatientPortalService();
        $scope = $portal->activeScope($this->ip());
        if ($scope === null || !$portal->releasedReportByToken($token, $scope)) {
            $this->json(['ok' => false, 'msg' => 'Laudo não encontrado ou não disponível para compartilhamento.'], 404);
            return;
        }

        try {
            $channel = (string) ($_POST['channel'] ?? '');
            $share = new PortalShareService();
            if ($channel === 'whatsapp') {
                $result = $share->createWhatsappLink($token, $scope, (string) ($_POST['phone'] ?? ''), $this->ip());
                $this->json(['ok' => true, 'action' => 'whatsapp', 'url' => $result['whatsapp_url'], 'expires_at' => $result['expires_at']]);
                return;
            }
            if ($channel === 'email') {
                $result = $share->sendEmail($token, $scope, (string) ($_POST['email'] ?? ''), $this->ip());
                $this->json(['ok' => true, 'action' => 'email', 'expires_at' => $result['expires_at']]);
                return;
            }
            $this->json(['ok' => false, 'msg' => 'Canal de compartilhamento inválido.'], 422);
        } catch (\DomainException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Logger::error('PatientPortalController::share falhou', ['error' => $e->getMessage(), 'ip' => $this->ip()]);
            $this->json(['ok' => false, 'msg' => 'Não foi possível concluir o compartilhamento agora.'], 500);
        }
    }

    public function sharedPdf(string $token): void
    {
        $share = new PortalShareService();
        $report = $share->sharedReportByToken($token);
        if ($report === null) {
            http_response_code(404);
            echo 'Link expirado ou inválido.';
            return;
        }
        try {
            (new ReportPdfService())->stream((object) $report['study'], (object) $report['report']);
        } catch (\Throwable $e) {
            Logger::error('PatientPortalController::sharedPdf falhou', ['error' => $e->getMessage()]);
            http_response_code(500);
            echo 'Não foi possível gerar o laudo compartilhado.';
        }
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
