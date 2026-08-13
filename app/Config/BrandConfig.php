<?php
namespace App\Config;

/**
 * Nome de marca exibido ao usuário para o servidor PACS.
 *
 * A integração técnica continua usando Orthanc: serviços, tabelas, URLs,
 * endpoints, logs e telas administrativas de configuração preservam essa
 * nomenclatura para não ocultar dados operacionais de quem a administra.
 */
final class BrandConfig
{
    public const PACS_SERVER_NAME = 'VOXEL PACS';
}
