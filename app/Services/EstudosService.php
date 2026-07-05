<?php
namespace App\Services;

use App\Repositories\EstudosRepository;

class EstudosService
{
    private EstudosRepository $repository;

    public function __construct()
    {
        $this->repository = new EstudosRepository();
    }

    public function getEstudosData(array $filtros, ?int $tenantId, bool $isAdmin): array
    {
        $whereData = $this->repository->buildWhereClause($filtros, $tenantId, $isAdmin);
        $whereStr = $whereData['where'];
        $params = $whereData['params'];

        $total = $this->repository->countEstudos($whereStr, $params);

        $porPagina   = $filtros['por_pagina'];
        $totalPages  = $porPagina > 0 ? max(1, (int)ceil($total / $porPagina)) : 1;
        $currentPage = min($filtros['pagina'], $totalPages);
        $offset      = $porPagina > 0 ? ($currentPage - 1) * $porPagina : 0;

        $estudos = $this->repository->getEstudos(
            $whereStr, 
            $params, 
            'e.' . $filtros['ordenar'], 
            $filtros['direcao'], 
            $porPagina, 
            $offset
        );

        return [
            'estudos' => $estudos,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $currentPage
        ];
    }

    public function getSelectData(?int $tenantId, bool $isAdmin): array
    {
        return [
            'unidades' => $this->repository->getUnidades($tenantId, $isAdmin),
            'medicos' => $this->repository->getMedicos($tenantId, $isAdmin),
            'especialidades' => $this->repository->getEspecialidades($tenantId, $isAdmin)
        ];
    }

    public function getDashboardData(?int $tenantId, bool $isAdmin, string $today): array
    {
        $contadores = $this->repository->getContadores($tenantId, $isAdmin);
        $resumo = $this->repository->getResumoPainel($tenantId, $isAdmin, $today, $contadores['urgente']);
        $ultimaSinc = $this->repository->getUltimaSincronizacao();

        return [
            'contadores' => $contadores,
            'resumo' => $resumo,
            'ultimaSinc' => $ultimaSinc
        ];
    }

    public function getEstudoForViewer(int $id, ?int $tenantId, bool $isAdmin): ?array
    {
        return $this->repository->getEstudoById($id, $tenantId, $isAdmin);
    }
}
