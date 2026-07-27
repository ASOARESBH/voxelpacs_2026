<?php
declare(strict_types=1);

namespace App\Services;

/**
 * StudyDescriptionColor
 *
 * Determina a cor do badge da coluna ESTUDO com base no texto livre
 * de study_description (DICOM 0008,1030).
 *
 * A comparação é case-insensitive e normaliza acentos antes de comparar,
 * aceitando variações como:
 *   "TC CRANIO", "TC CRÂNIO", "Tomografia", "CT HEAD"
 *
 * Paleta:
 *   TC / TOMOGRAFIA / CT            → Azul          #1d6fa4 / #e8f4fd
 *   RM / RESSONÂNCIA / MR           → Roxo           #6d28d9 / #ede9fe
 *   RX / RAIO X / CR / DX          → Laranja        #c2410c / #fff7ed
 *   US / ULTRASSOM / ECO            → Verde          #15803d / #f0fdf4
 *   MG / MAMOGRAFIA                 → Rosa           #be185d / #fdf2f8
 *   XA / ANGIO                      → Vermelho       #b91c1c / #fef2f2
 *   PET                             → Roxo Escuro    #4c1d95 / #f5f3ff
 *   NM                              → Verde Escuro   #14532d / #dcfce7
 *   OT                              → Cinza          #374151 / #f3f4f6
 *   (outro)                         → Azul Claro     #0369a1 / #e0f2fe
 */
class StudyDescriptionColor
{
    /** @var array<string, array{bg: string, text: string, label: string}> */
    private const RULES = [
        // Ordem importa: mais específico primeiro
        // XA/ANGIO antes de TC para que "ANGIO TC" seja classificado como XA
        'pet'        => ['bg' => '#4c1d95', 'text' => '#fff',    'label' => 'PET',   'terms' => ['pet']],
        'nm'         => ['bg' => '#14532d', 'text' => '#fff',    'label' => 'NM',    'terms' => ['nm', 'medicina nuclear', 'cintilografia']],
        'xa'         => ['bg' => '#b91c1c', 'text' => '#fff',    'label' => 'XA',    'terms' => ['xa ', 'xa\t', 'xa-', 'angio', 'angiografia', 'angiography', 'fluoroscopia']],
        'mg'         => ['bg' => '#be185d', 'text' => '#fff',    'label' => 'MG',    'terms' => ['mg ', 'mg\t', 'mg-', 'mamografia', 'mamografico', 'mamografica', 'mammography']],
        'us'         => ['bg' => '#15803d', 'text' => '#fff',    'label' => 'US',    'terms' => ['us ', 'us\t', 'us-', 'ultrassom', 'ultrassonografia', 'ecografia', 'eco ', 'eco\t', 'eco-', 'doppler', 'ultrasound', 'sonography']],
        'rm'         => ['bg' => '#6d28d9', 'text' => '#fff',    'label' => 'RM',    'terms' => ['rm ', 'rm\t', 'rm-', 'ressonancia', 'ressonância', 'mr ', 'mr\t', 'mr-', 'magnetic resonance']],
        'tc'         => ['bg' => '#1d6fa4', 'text' => '#fff',    'label' => 'TC',    'terms' => ['tc ', 'tc\t', 'tc-', 'tomografia', 'tomografico', 'tomografica', 'ct ', 'ct\t', 'ct-', 'computed tomography']],
        'rx'         => ['bg' => '#c2410c', 'text' => '#fff',    'label' => 'RX',    'terms' => ['rx ', 'rx\t', 'rx-', 'raio x', 'raio-x', 'radiografia', 'radiologico', 'radiologica', 'cr ', 'cr\t', 'cr-', 'dx ', 'dx\t', 'dx-', 'x-ray', 'radiograph']],
        'ot'         => ['bg' => '#374151', 'text' => '#fff',    'label' => 'OT',    'terms' => ['ot ', 'ot\t', 'ot-']],
    ];

    private const DEFAULT = ['bg' => '#0369a1', 'text' => '#fff', 'label' => ''];

    /**
     * Retorna ['bg' => string, 'text' => string, 'label' => string]
     * para o texto informado.
     *
     * @param  string $text  Valor de study_description (pode ser vazio)
     * @return array{bg: string, text: string, label: string}
     */
    public static function resolve(string $text): array
    {
        if ($text === '') {
            return self::DEFAULT;
        }

        $norm = self::normalize($text);

        foreach (self::RULES as $key => $rule) {
            foreach ($rule['terms'] as $term) {
                // Busca por substring normalizada
                if (strpos($norm, self::normalize($term)) !== false) {
                    return [
                        'bg'    => $rule['bg'],
                        'text'  => $rule['text'],
                        'label' => $rule['label'],
                    ];
                }
            }
        }

        return self::DEFAULT;
    }

    /**
     * Normaliza string: remove acentos + lowercase + colapsa espaços.
     */
    public static function normalize(string $s): string
    {
        // Remove acentos via transliteração
        $map = [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
            'ç'=>'c','ñ'=>'n',
            'Á'=>'a','À'=>'a','Ã'=>'a','Â'=>'a','Ä'=>'a',
            'É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e',
            'Í'=>'i','Ì'=>'i','Î'=>'i','Ï'=>'i',
            'Ó'=>'o','Ò'=>'o','Õ'=>'o','Ô'=>'o','Ö'=>'o',
            'Ú'=>'u','Ù'=>'u','Û'=>'u','Ü'=>'u',
            'Ç'=>'c','Ñ'=>'n',
        ];
        $s = strtr($s, $map);
        $s = strtolower($s);
        // Adiciona espaço no início e fim para facilitar matching de termos curtos
        return ' ' . trim($s) . ' ';
    }
}
