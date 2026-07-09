<?php
namespace App\Config;

/**
 * Constantes do módulo SLA (Fase 1 — Worklist de Estudos).
 * Limites em minutos, usados tanto pelo backend quanto repassados ao
 * frontend (sla-counter.js) via toArray(), evitando valores hardcoded
 * em duas linguagens.
 */
final class SlaConfig
{
    public const VERDE_MAX_MIN   = 30;
    public const AMARELO_MAX_MIN = 120;
    public const LARANJA_MAX_MIN = 360;

    public static function toArray(): array
    {
        return [
            'verdeMaxMin'   => self::VERDE_MAX_MIN,
            'amareloMaxMin' => self::AMARELO_MAX_MIN,
            'laranjaMaxMin' => self::LARANJA_MAX_MIN,
        ];
    }
}
