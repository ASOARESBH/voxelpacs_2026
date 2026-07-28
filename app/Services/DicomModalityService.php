<?php
namespace App\Services;

use App\Core\Translator;

/**
 * VOXEL PACS — DicomModalityService
 *
 * Catálogo centralizado de descrições de modalidades DICOM (tag Modality,
 * 0008,0060), traduzido nos 3 idiomas suportados pelo sistema (pt_BR/en/es).
 *
 * Único ponto de verdade para "o que significa a sigla CR/CT/MR/...": nenhuma
 * View deve ter texto de descrição de modalidade hardcoded — sempre chamar
 * DicomModalityService::description($codigo).
 *
 * Locale: detectado automaticamente via App\Core\Translator::locale() (mesmo
 * mecanismo já usado pela função global t() — resolvido uma vez por request
 * em TenantMiddleware a partir de bi_tenants.idioma_padrao), a menos que um
 * locale explícito seja passado (útil para APIs que recebem ?lang=xx).
 *
 * @since 2026-07-27
 */
class DicomModalityService
{
    /**
     * code => [locale => descrição].
     * Chaves de locale seguem exatamente App\Core\Translator::SUPPORTED
     * ('pt_BR', 'en', 'es') — nunca 'pt-BR'/'en-US'/'es-ES'.
     */
    private const CATALOG = [
        'CR'       => ['pt_BR' => 'Radiografia Computadorizada',                       'en' => 'Computed Radiography',                         'es' => 'Radiografía Computarizada'],
        'CT'       => ['pt_BR' => 'Tomografia Computadorizada',                        'en' => 'Computed Tomography',                          'es' => 'Tomografía Computarizada'],
        'MR'       => ['pt_BR' => 'Ressonância Magnética',                             'en' => 'Magnetic Resonance',                           'es' => 'Resonancia Magnética'],
        'US'       => ['pt_BR' => 'Ultrassonografia',                                  'en' => 'Ultrasound',                                   'es' => 'Ultrasonido'],
        'DX'       => ['pt_BR' => 'Radiografia Digital',                               'en' => 'Digital Radiography',                          'es' => 'Radiografía Digital'],
        'MG'       => ['pt_BR' => 'Mamografia',                                        'en' => 'Mammography',                                  'es' => 'Mamografía'],
        'XA'       => ['pt_BR' => 'Angiografia por Raios X',                           'en' => 'X-Ray Angiography',                            'es' => 'Angiografía por Rayos X'],
        'NM'       => ['pt_BR' => 'Medicina Nuclear',                                  'en' => 'Nuclear Medicine',                             'es' => 'Medicina Nuclear'],
        'PT'       => ['pt_BR' => 'Tomografia por Emissão de Pósitrons',               'en' => 'Positron Emission Tomography',                 'es' => 'Tomografía por Emisión de Positrones'],
        'RF'       => ['pt_BR' => 'Radioscopia',                                       'en' => 'Radio Fluoroscopy',                            'es' => 'Radioscopia'],
        'OT'       => ['pt_BR' => 'Outro',                                             'en' => 'Other',                                        'es' => 'Otro'],
        'ECG'      => ['pt_BR' => 'Eletrocardiograma',                                 'en' => 'Electrocardiogram',                            'es' => 'Electrocardiograma'],

        // Cobertura estendida — lista pedida explicitamente
        'ES'       => ['pt_BR' => 'Endoscopia',                                        'en' => 'Endoscopy',                                    'es' => 'Endoscopia'],
        'DOC'      => ['pt_BR' => 'Documento',                                         'en' => 'Document',                                     'es' => 'Documento'],
        'KO'       => ['pt_BR' => 'Seleção de Objeto-Chave',                           'en' => 'Key Object Selection',                         'es' => 'Selección de Objeto Clave'],
        'PR'       => ['pt_BR' => 'Estado de Apresentação',                            'en' => 'Presentation State',                           'es' => 'Estado de Presentación'],
        'SR'       => ['pt_BR' => 'Laudo Estruturado',                                 'en' => 'Structured Report',                            'es' => 'Informe Estructurado'],
        'RTIMAGE'  => ['pt_BR' => 'Imagem de Radioterapia',                            'en' => 'RT Image',                                     'es' => 'Imagen de Radioterapia'],
        'RTSTRUCT' => ['pt_BR' => 'Conjunto de Estruturas de Radioterapia',            'en' => 'RT Structure Set',                             'es' => 'Conjunto de Estructuras de Radioterapia'],
        'RTPLAN'   => ['pt_BR' => 'Plano de Radioterapia',                             'en' => 'RT Plan',                                      'es' => 'Plan de Radioterapia'],
        'RTDOSE'   => ['pt_BR' => 'Dose de Radioterapia',                              'en' => 'RT Dose',                                      'es' => 'Dosis de Radioterapia'],
        'SC'       => ['pt_BR' => 'Captura Secundária',                                'en' => 'Secondary Capture',                            'es' => 'Captura Secundaria'],
        'SEG'      => ['pt_BR' => 'Segmentação',                                       'en' => 'Segmentation',                                 'es' => 'Segmentación'],
        'SM'       => ['pt_BR' => 'Microscopia de Lâmina',                             'en' => 'Slide Microscopy',                             'es' => 'Microscopía de Portaobjetos'],
        'OP'       => ['pt_BR' => 'Fotografia Oftálmica',                              'en' => 'Ophthalmic Photography',                       'es' => 'Fotografía Oftálmica'],
        'OPT'      => ['pt_BR' => 'Tomografia Oftálmica',                              'en' => 'Ophthalmic Tomography',                        'es' => 'Tomografía Oftálmica'],
        'OPM'      => ['pt_BR' => 'Mapeamento Oftálmico',                              'en' => 'Ophthalmic Mapping',                           'es' => 'Mapeo Oftálmico'],
        'IVOCT'    => ['pt_BR' => 'Tomografia de Coerência Óptica Intravascular',      'en' => 'Intravascular Optical Coherence Tomography',   'es' => 'Tomografía de Coherencia Óptica Intravascular'],
        'OCT'      => ['pt_BR' => 'Tomografia de Coerência Óptica',                    'en' => 'Optical Coherence Tomography',                 'es' => 'Tomografía de Coherencia Óptica'],
        'PX'       => ['pt_BR' => 'Radiografia Panorâmica',                            'en' => 'Panoramic X-Ray',                              'es' => 'Radiografía Panorámica'],
        'REG'      => ['pt_BR' => 'Registro de Imagens',                               'en' => 'Registration',                                 'es' => 'Registro de Imágenes'],

        // Demais termos oficiais do DICOM PS3.3 (Modality — Defined Terms), para
        // cobertura mais ampla além da lista mínima pedida.
        'AU'       => ['pt_BR' => 'Áudio',                                             'en' => 'Audio',                                        'es' => 'Audio'],
        'BDUS'     => ['pt_BR' => 'Densitometria Óssea (Ultrassom)',                   'en' => 'Bone Densitometry (Ultrasound)',               'es' => 'Densitometría Ósea (Ultrasonido)'],
        'BMD'      => ['pt_BR' => 'Densitometria Óssea (Raios X)',                     'en' => 'Bone Densitometry (X-Ray)',                    'es' => 'Densitometría Ósea (Rayos X)'],
        'BI'       => ['pt_BR' => 'Imagem Biomagnética',                               'en' => 'Biomagnetic Imaging',                          'es' => 'Imagen Biomagnética'],
        'DG'       => ['pt_BR' => 'Diafanografia',                                     'en' => 'Diaphanography',                               'es' => 'Diafanografía'],
        'EPS'      => ['pt_BR' => 'Eletrofisiologia Cardíaca',                         'en' => 'Cardiac Electrophysiology',                    'es' => 'Electrofisiología Cardíaca'],
        'GM'       => ['pt_BR' => 'Microscopia Geral',                                 'en' => 'General Microscopy',                           'es' => 'Microscopía General'],
        'HC'       => ['pt_BR' => 'Cópia Impressa',                                    'en' => 'Hard Copy',                                    'es' => 'Copia Impresa'],
        'HD'       => ['pt_BR' => 'Forma de Onda Hemodinâmica',                        'en' => 'Hemodynamic Waveform',                         'es' => 'Forma de Onda Hemodinámica'],
        'IO'       => ['pt_BR' => 'Radiografia Intraoral',                             'en' => 'Intra-Oral Radiography',                       'es' => 'Radiografía Intraoral'],
        'IOL'      => ['pt_BR' => 'Dados de Lente Intraocular',                        'en' => 'Intraocular Lens Data',                        'es' => 'Datos de Lente Intraocular'],
        'IVUS'     => ['pt_BR' => 'Ultrassom Intravascular',                           'en' => 'Intravascular Ultrasound',                     'es' => 'Ultrasonido Intravascular'],
        'KER'      => ['pt_BR' => 'Ceratometria',                                      'en' => 'Keratometry',                                  'es' => 'Queratometría'],
        'LEN'      => ['pt_BR' => 'Lensometria',                                       'en' => 'Lensometry',                                   'es' => 'Lensometría'],
        'LS'       => ['pt_BR' => 'Escaneamento de Superfície a Laser',                'en' => 'Laser Surface Scan',                           'es' => 'Escaneo de Superficie Láser'],
        'OAM'      => ['pt_BR' => 'Medidas Axiais Oftálmicas',                         'en' => 'Ophthalmic Axial Measurements',                'es' => 'Medidas Axiales Oftálmicas'],
        'OPV'      => ['pt_BR' => 'Campo Visual Oftálmico',                            'en' => 'Ophthalmic Visual Field',                      'es' => 'Campo Visual Oftálmico'],
        'OSS'      => ['pt_BR' => 'Escaneamento de Superfície Óptica',                 'en' => 'Optical Surface Scan',                         'es' => 'Escaneo de Superficie Óptica'],
        'PLAN'     => ['pt_BR' => 'Plano de Tratamento',                               'en' => 'Plan',                                         'es' => 'Plan de Tratamiento'],
        'RESP'     => ['pt_BR' => 'Forma de Onda Respiratória',                        'en' => 'Respiratory Waveform',                         'es' => 'Forma de Onda Respiratoria'],
        'RG'       => ['pt_BR' => 'Radiografia Convencional (Filme)',                  'en' => 'Radiographic Imaging (Film/Screen)',           'es' => 'Radiografía Convencional (Película)'],
        'RTRECORD' => ['pt_BR' => 'Registro de Tratamento de Radioterapia',            'en' => 'RT Treatment Record',                          'es' => 'Registro de Tratamiento de Radioterapia'],
        'RWV'      => ['pt_BR' => 'Mapa de Valor do Mundo Real',                       'en' => 'Real World Value Map',                         'es' => 'Mapa de Valor del Mundo Real'],
        'SMR'      => ['pt_BR' => 'Relação Estereométrica',                            'en' => 'Stereometric Relationship',                    'es' => 'Relación Estereométrica'],
        'SRF'      => ['pt_BR' => 'Refração Subjetiva',                                'en' => 'Subjective Refraction',                        'es' => 'Refracción Subjetiva'],
        'STAIN'    => ['pt_BR' => 'Coloração Automatizada de Lâmina',                  'en' => 'Automated Slide Stainer',                      'es' => 'Tinción Automatizada de Portaobjetos'],
        'TG'       => ['pt_BR' => 'Termografia',                                       'en' => 'Thermography',                                 'es' => 'Termografía'],
        'VA'       => ['pt_BR' => 'Acuidade Visual',                                   'en' => 'Visual Acuity',                                'es' => 'Agudeza Visual'],
        'XC'       => ['pt_BR' => 'Fotografia com Câmera Externa',                     'en' => 'External-Camera Photography',                  'es' => 'Fotografía con Cámara Externa'],
    ];

    private const UNKNOWN = [
        'pt_BR' => 'Modalidade DICOM desconhecida',
        'en'    => 'Unknown DICOM Modality',
        'es'    => 'Modalidad DICOM desconocida',
    ];

    /**
     * Descrição da modalidade no idioma ativo do sistema (ou no $locale
     * explícito, se informado — útil para APIs que recebem ?lang=xx).
     */
    public static function description(?string $codigo, ?string $locale = null): string
    {
        $locale = $locale ?? Translator::locale();
        $codigo = strtoupper(trim((string) $codigo));

        $entry = self::CATALOG[$codigo] ?? self::UNKNOWN;

        return $entry[$locale] ?? $entry['pt_BR'];
    }

    /** Sigla normalizada (maiúscula, sem espaços) — a mesma sempre exibida na coluna. */
    public static function code(?string $codigo): string
    {
        return strtoupper(trim((string) $codigo));
    }

    /** true se o código existe no catálogo (não cai no fallback "desconhecida"). */
    public static function isKnown(?string $codigo): bool
    {
        return isset(self::CATALOG[strtoupper(trim((string) $codigo))]);
    }
}
