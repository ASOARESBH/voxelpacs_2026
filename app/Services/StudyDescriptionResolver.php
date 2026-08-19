<?php
namespace App\Services;

/**
 * Resolve a descrição clínica exibida para um estudo DICOM.
 *
 * Mantém uma única prioridade para Worklist, Laudário, Viewer e integrações:
 * (0008,1030) Study Description → (0040,0007) Scheduled Procedure Step
 * Description → (0032,1060) Requested Procedure Description → (0018,0015)
 * Body Part Examined.
 */
final class StudyDescriptionResolver
{
    /**
     * @param array<string,mixed>|object $study
     * @return array{description:string,source:string,tag:string}
     */
    public static function resolve(array|object $study): array
    {
        $sources = [
            ['field' => 'study_description',             'source' => 'Study Description',                    'tag' => '(0008,1030)'],
            ['field' => 'scheduled_procedure_step_desc', 'source' => 'Scheduled Procedure Step Description', 'tag' => '(0040,0007)'],
            ['field' => 'requested_procedure_desc',      'source' => 'Requested Procedure Description',      'tag' => '(0032,1060)'],
            ['field' => 'body_part_examined',            'source' => 'Body Part Examined',                   'tag' => '(0018,0015)'],
        ];

        foreach ($sources as $source) {
            $value = self::value($study, $source['field']);
            if ($value !== '') {
                return [
                    'description' => $value,
                    'source' => $source['source'],
                    'tag' => $source['tag'],
                ];
            }
        }

        return [
            'description' => '',
            'source' => 'Descrição DICOM não informada',
            'tag' => '',
        ];
    }

    /** @param array<string,mixed>|object $study */
    public static function text(array|object $study, string $fallback = ''): string
    {
        $description = self::resolve($study)['description'];
        return $description !== '' ? $description : $fallback;
    }

    /** @param array<string,mixed>|object $study */
    private static function value(array|object $study, string $field): string
    {
        $value = is_array($study) ? ($study[$field] ?? '') : ($study->{$field} ?? '');
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
