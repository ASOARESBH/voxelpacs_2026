<?php
namespace App\Helpers;

/**
 * Formata um DICOM Person Name (VR = PN) para exibição legível.
 *
 * O DICOM PN usa "^" para separar componentes (FamilyName^GivenName^MiddleName^
 * Prefix^Suffix). O valor cru nunca deve chegar à interface — só a camada de
 * apresentação é afetada; o dado gravado no banco permanece intacto.
 */
class DicomPersonName
{
    public static function format(?string $pn): string
    {
        if (empty($pn)) {
            return '';
        }

        $pn = trim($pn);

        $parts = explode('^', $pn);

        $parts = array_filter(array_map('trim', $parts));

        return implode(' ', $parts);
    }
}
