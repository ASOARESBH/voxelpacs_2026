<?php
namespace App\Services;

/**
 * VOXEL PACS — BodyPartExtractor
 *
 * Extrai partes do corpo reconhecidas a partir do texto livre de Study Description
 * (0008,1030). Retorna uma lista de chaves canônicas de partes do corpo, que são
 * mapeadas para nomes i18n e cores de badge na camada de apresentação.
 *
 * Design:
 *  - Função pura: sem estado, sem I/O, sem dependências externas.
 *  - Testável isoladamente.
 *  - Reutilizável em qualquer parte do sistema (worklist, relatórios, BI).
 *
 * Uso:
 *   $partes = BodyPartExtractor::extract('RX DE TORAX PA E PERFIL');
 *   // → ['thorax']
 *
 *   $partes = BodyPartExtractor::extract('TORAX E ABDOME');
 *   // → ['thorax', 'abdomen']
 *
 *   $partes = BodyPartExtractor::extract('As requested by the family.');
 *   // → []  (texto sem parte reconhecida)
 *
 * @since 2026-07-27
 */
class BodyPartExtractor
{
    /**
     * Dicionário de reconhecimento.
     *
     * Estrutura: 'chave_canonica' => ['termo1', 'termo2', ...]
     *
     * Regras:
     *  - Todos os termos em MINÚSCULAS e SEM ACENTOS (normalização aplicada
     *    antes da comparação, tanto no dicionário quanto no texto de entrada).
     *  - A busca é por SUBSTRING (strpos), não por palavra exata — cobre
     *    casos como "RX DE TORAX PA E PERFIL" ou "US ABDOME TOTAL".
     *  - Termos mais específicos devem vir ANTES dos genéricos para evitar
     *    falsos positivos (ex: "coluna cervical" antes de "coluna").
     *  - Ordem do array define prioridade de detecção.
     */
    private const DICTIONARY = [
        // ── Crânio / Cabeça ──────────────────────────────────────────────────
        // NOTA: 'seio' removido — ambíguo (seio da face vs mama). Usar 'sinus'.
        'skull' => [
            'cranio', 'skull', 'head', 'cabeca',
            'cerebro', 'brain', 'face', 'facial',
            'orbita', 'orbit', 'sinus', 'mastoid',
            'mastoide', 'temporal', 'mandibula',
        ],
        // ── Pescoço ──────────────────────────────────────────────────────────
        'neck' => [
            'pescoco', 'pescoço', 'neck', 'cervical soft',
            'laringe', 'larynx', 'faringe', 'pharynx',
            'tireoide', 'tireóide', 'thyroid',
        ],
        // ── Tórax ────────────────────────────────────────────────────────────
        'thorax' => [
            'torax', 'tórax', 'thorax', 'chest', 'thoracic',
            'pulm', 'pulmao', 'pulmão', 'lung', 'pleura',
            'mediastino', 'mediastinum', 'costela', 'rib',
            'esterno', 'sternum', 'clavic',
        ],
        // ── Coluna Cervical ───────────────────────────────────────────────────
        'spine_cervical' => [
            'coluna cervical', 'cervical spine', 'spine cervical',
            'c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7',
            'rachis cervical',
        ],
        // ── Coluna Torácica ───────────────────────────────────────────────────
        'spine_thoracic' => [
            'coluna toracica', 'coluna torácica', 'thoracic spine',
            'spine thoracic', 'rachis toracico',
        ],
        // ── Coluna Lombar ─────────────────────────────────────────────────────
        'spine_lumbar' => [
            'coluna lombar', 'lumbar spine', 'spine lumbar',
            'lombossacra', 'lombossacral', 'lumbosacral',
            'l1', 'l2', 'l3', 'l4', 'l5',
        ],
        // ── Coluna (genérico — só se nenhuma específica foi encontrada) ───────
        'spine' => [
            'coluna', 'spine', 'rachis', 'vertebra', 'vertebral',
            'sacro', 'sacrum', 'sacral', 'coccix', 'cocix',
        ],
        // ── Abdômen ───────────────────────────────────────────────────────────
        // NOTA: 'rim'/'baco' removidos — 'rim' é muito curto (falsos positivos),
        // 'baco' pode aparecer em nomes. Usar 'renal'/'kidney'/'spleen'.
        'abdomen' => [
            'abdome', 'abdomen', 'abdominal',
            'figado', 'liver', 'baco', 'spleen',
            'pancreas', 'renal', 'kidney',
            'vesic', 'gallbladder', 'colon', 'intestin',
            'retroperiton', 'aorta abdominal',
        ],
        // ── Pelve / Bacia / Quadril ───────────────────────────────────────────
        'pelvis' => [
            'pelve', 'pelvis', 'pelvic', 'bacia', 'quadril', 'hip',
            'sacroiliaca', 'sacroilíaca', 'sacroiliac',
            'ilio', 'ilíaco', 'iliac', 'pubis', 'pubic',
            'acetabulo', 'acetábulo', 'acetabulum',
        ],
        // ── Ombro ─────────────────────────────────────────────────────────────
        'shoulder' => [
            'ombro', 'shoulder', 'escapula', 'escápula', 'scapula',
            'acromi', 'glenoumeral', 'glenohumeral', 'manguito',
            'rotator cuff',
        ],
        // ── Braço / Cotovelo ──────────────────────────────────────────────────
        'arm' => [
            'braco', 'braço', 'arm', 'umero', 'úmero', 'humerus',
            'cotovelo', 'elbow', 'olecrano', 'olécrano', 'olecranon',
        ],
        // ── Antebraço / Punho / Mão ───────────────────────────────────────────
        // NOTA: 'mao'/'mão' removidos — causam falsos positivos em nomes de pacientes.
        'forearm' => [
            'antebraco', 'antebraco', 'forearm', 'radio', 'radius',
            'ulna', 'punho', 'wrist', 'hand',
            'carpo', 'carpus', 'metacarpo', 'metacarpus',
            'dedo', 'finger', 'falange', 'phalanx',
        ],
        // ── Coxa / Joelho ─────────────────────────────────────────────────────
        'knee' => [
            'coxa', 'femur', 'fêmur', 'femoral', 'joelho', 'knee',
            'patela', 'patella', 'rotula', 'rótula', 'tibia', 'tíbia',
            'fibula', 'fíbula', 'perone', 'perônio',
        ],
        // ── Perna / Tornozelo / Pé ────────────────────────────────────────────
        // NOTA: 'pe'/'leg' removidos — muito curtos, causam falsos positivos
        // (ex: 'perfil', 'pescoco', 'pelvis'). Usar termos mais específicos.
        'foot' => [
            'perna', 'tornozelo', 'ankle', 'foot',
            'calcaneo', 'calcaneus', 'tarso', 'tarsus',
            'metatarso', 'metatarsus', 'halux', 'hallux',
        ],
        // ── Mama ──────────────────────────────────────────────────────────────
        'breast' => [
            'mama', 'breast', 'mamografia', 'mammography',
            'mamaria', 'mamário', 'mammary',
        ],
    ];

    /**
     * Chaves canônicas que devem ser verificadas ANTES das genéricas
     * para evitar que "coluna" capture "coluna cervical".
     * A ordem aqui é a ordem de varredura do dicionário.
     */
    private const PRIORITY_ORDER = [
        'skull',
        'neck',
        'thorax',
        'spine_cervical',
        'spine_thoracic',
        'spine_lumbar',
        'spine',
        'abdomen',
        'pelvis',
        'shoulder',
        'arm',
        'forearm',
        'knee',
        'foot',
        'breast',
    ];

    /**
     * Cores de badge por chave canônica.
     * Cores fixas — não mudam por estudo.
     */
    public const COLORS = [
        'skull'          => ['bg' => '#8B5CF6', 'text' => '#fff'],
        'neck'           => ['bg' => '#A855F7', 'text' => '#fff'],
        'thorax'         => ['bg' => '#3B82F6', 'text' => '#fff'],
        'spine_cervical' => ['bg' => '#0EA5E9', 'text' => '#fff'],
        'spine_thoracic' => ['bg' => '#0EA5E9', 'text' => '#fff'],
        'spine_lumbar'   => ['bg' => '#0EA5E9', 'text' => '#fff'],
        'spine'          => ['bg' => '#0EA5E9', 'text' => '#fff'],
        'abdomen'        => ['bg' => '#F97316', 'text' => '#fff'],
        'pelvis'         => ['bg' => '#92400E', 'text' => '#fff'],
        'shoulder'       => ['bg' => '#EC4899', 'text' => '#fff'],
        'arm'            => ['bg' => '#F472B6', 'text' => '#1a1a1a'],
        'forearm'        => ['bg' => '#EAB308', 'text' => '#1a1a1a'],
        'knee'           => ['bg' => '#22C55E', 'text' => '#fff'],
        'foot'           => ['bg' => '#14B8A6', 'text' => '#fff'],
        'breast'         => ['bg' => '#D946EF', 'text' => '#fff'],
    ];

    /**
     * Chaves i18n para o nome de exibição de cada parte do corpo.
     * O valor é a chave usada em lang/pt_BR.php, lang/en.php, lang/es.php.
     */
    public const I18N_KEYS = [
        'skull'          => 'body_part.skull',
        'neck'           => 'body_part.neck',
        'thorax'         => 'body_part.thorax',
        'spine_cervical' => 'body_part.spine_cervical',
        'spine_thoracic' => 'body_part.spine_thoracic',
        'spine_lumbar'   => 'body_part.spine_lumbar',
        'spine'          => 'body_part.spine',
        'abdomen'        => 'body_part.abdomen',
        'pelvis'         => 'body_part.pelvis',
        'shoulder'       => 'body_part.shoulder',
        'arm'            => 'body_part.arm',
        'forearm'        => 'body_part.forearm',
        'knee'           => 'body_part.knee',
        'foot'           => 'body_part.foot',
        'breast'         => 'body_part.breast',
    ];

    /**
     * Extrai partes do corpo reconhecidas de um texto livre.
     *
     * @param  string|null $text  Texto da Study Description (0008,1030)
     * @return string[]           Lista de chaves canônicas reconhecidas (sem duplicatas, na ordem do dicionário)
     */
    public static function extract(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $normalized = self::normalize($text);
        $found      = [];

        foreach (self::PRIORITY_ORDER as $key) {
            if (!isset(self::DICTIONARY[$key])) {
                continue;
            }

            // Exclusão mútua: se uma coluna específica já foi encontrada,
            // não adicionar a chave genérica 'spine'.
            if ($key === 'spine' && (
                in_array('spine_cervical', $found, true) ||
                in_array('spine_thoracic', $found, true) ||
                in_array('spine_lumbar',   $found, true)
            )) {
                continue;
            }

            foreach (self::DICTIONARY[$key] as $term) {
                if (strpos($normalized, $term) !== false) {
                    $found[] = $key;
                    break; // já encontrou esta parte, passa para a próxima chave
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Normaliza texto para comparação:
     * - Converte para minúsculas
     * - Remove acentos (transliteração ASCII)
     *
     * @param  string $text
     * @return string
     */
    public static function normalize(string $text): string
    {
        // 1. Remove acentos ANTES do strtolower (para cobrir maiúsculas acentuadas)
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

        // 2. Converte para minúsculas após remoção de acentos
        return strtolower(strtr($text, $map));
    }
}
