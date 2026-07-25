-- FASE 9: Performance e Normalização
-- Adiciona índices para otimizar as consultas baseadas em InstitutionName

ALTER TABLE `bi_negocio_institution_names`
ADD INDEX `idx_institution_name` (`institution_name`);

ALTER TABLE `bi_pacs_estudos`
ADD INDEX `idx_institution_name` (`institution_name`);

-- Correção dos dados existentes:
-- A unidade NOVA IMAGEM - CAMBUÍ (com acento) está na tabela bi_negocio_institution_names
-- Mas os estudos têm NOVA IMAGEM - CAMBUI (sem acento)
-- Vamos normalizar a tabela bi_negocio_institution_names para bater com o DICOM real

UPDATE `bi_negocio_institution_names` 
SET `institution_name` = 'NOVA IMAGEM - CAMBUI' 
WHERE `institution_name` = 'NOVA IMAGEM - CAMBUÍ';

-- Os estudos do tenant 1 (INOVA, NOVA IMAGEM - CAMBUI, Vivere)
-- Na verdade pertencem ao tenant 2 (ORIX TELERRADIOLOGIA)
-- Atualizando o tenant_id dos estudos com base no InstitutionName

UPDATE `bi_pacs_estudos`
SET `tenant_id` = 2
WHERE `institution_name` IN ('INOVA', 'NOVA IMAGEM - CAMBUI', 'CEAE PATOS DE MINAS', 'Vivere');

-- Inserindo INOVA e Vivere na tabela de institution_names do tenant 2
INSERT IGNORE INTO `bi_negocio_institution_names` (`tenant_id`, `institution_name`, `ativo`) 
VALUES (2, 'INOVA', 1), (2, 'Vivere', 1);
