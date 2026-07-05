-- ============================================================
-- VOXEL PACS — Módulo Reports (Workflow de Laudo)
-- Compatível com MySQL 5.7.44 / MariaDB — Hostgator compartilhado
-- ⚠️  SEM IF NOT EXISTS em ADD INDEX (não suportado nessa versão)
-- Executar no phpMyAdmin — verificar existência antes de cada ALTER
-- Autor: VOXEL PACS Deploy — 2026-07-05
-- ============================================================

-- ============================================================
-- ⚠️  VERIFICAÇÕES ANTES DE EXECUTAR
-- ============================================================
-- SHOW TABLES LIKE 'reports';
-- SHOW TABLES LIKE 'report_versions';
-- SHOW TABLES LIKE 'report_templates';
-- SHOW TABLES LIKE 'report_logs';
-- SHOW TABLES LIKE 'report_autotext';
-- SHOW TABLES LIKE 'report_favorites';
-- SHOW TABLES LIKE 'report_signatures';
-- ============================================================

-- ============================================================
-- 1. REPORTS — Laudo principal
-- ============================================================
CREATE TABLE IF NOT EXISTS `reports` (
  `id`                  INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`           INT(11) UNSIGNED NOT NULL COMMENT 'FK → bi_tenants.id',
  `estudo_id`           INT(11) UNSIGNED NOT NULL COMMENT 'FK → bi_pacs_estudos.id',
  `study_instance_uid`  VARCHAR(255) NOT NULL COMMENT 'StudyInstanceUID DICOM',
  `usuario_id`          INT(11) UNSIGNED NOT NULL COMMENT 'FK → bi_users.id (médico responsável)',
  `situacao`            ENUM('rascunho','assinado','liberado') NOT NULL DEFAULT 'rascunho'
                        COMMENT 'Status do laudo',
  `secao_exame`         TEXT COMMENT 'Seção: EXAME',
  `secao_tecnica`       TEXT COMMENT 'Seção: TÉCNICA',
  `secao_achados`       TEXT COMMENT 'Seção: ACHADOS',
  `secao_conclusao`     TEXT COMMENT 'Seção: CONCLUSÃO',
  `secao_recomendacao`  TEXT COMMENT 'Seção: RECOMENDAÇÃO',
  `template_id`         INT(11) UNSIGNED DEFAULT NULL COMMENT 'FK → report_templates.id',
  `template_nome`       VARCHAR(100) DEFAULT NULL COMMENT 'Nome do template usado (snapshot)',
  `bloqueado_por`       INT(11) UNSIGNED DEFAULT NULL COMMENT 'FK → bi_users.id (médico editando)',
  `bloqueado_em`        DATETIME DEFAULT NULL COMMENT 'Timestamp do bloqueio',
  `assinado_por`        INT(11) UNSIGNED DEFAULT NULL COMMENT 'FK → bi_users.id',
  `assinado_em`         DATETIME DEFAULT NULL,
  `assinatura_hash`     VARCHAR(64) DEFAULT NULL COMMENT 'SHA-256 do conteúdo assinado',
  `assinatura_crm`      VARCHAR(30) DEFAULT NULL COMMENT 'CRM do médico no momento da assinatura',
  `liberado_por`        INT(11) UNSIGNED DEFAULT NULL COMMENT 'FK → bi_users.id',
  `liberado_em`         DATETIME DEFAULT NULL,
  `tempo_edicao_seg`    INT(11) DEFAULT 0 COMMENT 'Tempo total de edição em segundos',
  `ip_criacao`          VARCHAR(45) DEFAULT NULL,
  `ip_ultima_edicao`    VARCHAR(45) DEFAULT NULL,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_estudo_report` (`estudo_id`),
  INDEX `idx_reports_tenant` (`tenant_id`),
  INDEX `idx_reports_usuario` (`usuario_id`),
  INDEX `idx_reports_situacao` (`situacao`),
  INDEX `idx_reports_study_uid` (`study_instance_uid`(64)),
  INDEX `idx_reports_assinado_em` (`assinado_em`),
  INDEX `idx_reports_tenant_situacao` (`tenant_id`, `situacao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Laudos médicos do VOXEL PACS';

-- ============================================================
-- 2. REPORT_VERSIONS — Histórico / controle de versões
-- ============================================================
CREATE TABLE IF NOT EXISTS `report_versions` (
  `id`              INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id`       INT(11) UNSIGNED NOT NULL COMMENT 'FK → reports.id',
  `versao`          SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Número da versão',
  `usuario_id`      INT(11) UNSIGNED NOT NULL COMMENT 'FK → bi_users.id',
  `usuario_nome`    VARCHAR(150) DEFAULT NULL COMMENT 'Snapshot do nome',
  `acao`            VARCHAR(50) NOT NULL COMMENT 'rascunho|assinado|liberado|restaurado',
  `secao_exame`     TEXT,
  `secao_tecnica`   TEXT,
  `secao_achados`   TEXT,
  `secao_conclusao` TEXT,
  `secao_recomendacao` TEXT,
  `ip`              VARCHAR(45) DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_rv_report` (`report_id`),
  INDEX `idx_rv_usuario` (`usuario_id`),
  INDEX `idx_rv_report_versao` (`report_id`, `versao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Histórico de versões dos laudos';

-- ============================================================
-- 3. REPORT_TEMPLATES — Templates de laudo por modalidade
-- ============================================================
CREATE TABLE IF NOT EXISTS `report_templates` (
  `id`            INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`     INT(11) UNSIGNED DEFAULT NULL COMMENT 'NULL = template global da plataforma',
  `usuario_id`    INT(11) UNSIGNED DEFAULT NULL COMMENT 'FK → bi_users.id (criador)',
  `nome`          VARCHAR(150) NOT NULL COMMENT 'Nome do template',
  `modalidade`    VARCHAR(20) DEFAULT NULL COMMENT 'CR, CT, MR, US, MG, DX, XA, NM, PT, ECG, OT',
  `especialidade` VARCHAR(100) DEFAULT NULL,
  `secao_exame`   TEXT,
  `secao_tecnica` TEXT,
  `secao_achados` TEXT,
  `secao_conclusao` TEXT,
  `secao_recomendacao` TEXT,
  `ativo`         TINYINT(1) NOT NULL DEFAULT 1,
  `uso_count`     INT(11) NOT NULL DEFAULT 0 COMMENT 'Contador de uso',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_rt_tenant` (`tenant_id`),
  INDEX `idx_rt_modalidade` (`modalidade`),
  INDEX `idx_rt_ativo` (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Templates de laudo por modalidade/especialidade';

-- ============================================================
-- 4. REPORT_LOGS — Auditoria completa de ações
-- ============================================================
CREATE TABLE IF NOT EXISTS `report_logs` (
  `id`          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id`   INT(11) UNSIGNED DEFAULT NULL COMMENT 'FK → reports.id',
  `estudo_id`   INT(11) UNSIGNED DEFAULT NULL COMMENT 'FK → bi_pacs_estudos.id',
  `tenant_id`   INT(11) UNSIGNED DEFAULT NULL,
  `usuario_id`  INT(11) UNSIGNED DEFAULT NULL COMMENT 'FK → bi_users.id',
  `usuario_nome` VARCHAR(150) DEFAULT NULL COMMENT 'Snapshot do nome',
  `acao`        VARCHAR(60) NOT NULL
                COMMENT 'assumir|abertura|fechamento|salvar|rascunho|assinatura|visualizacao|pdf|template|restaurar',
  `descricao`   VARCHAR(500) DEFAULT NULL COMMENT 'Detalhes da ação',
  `ip`          VARCHAR(45) DEFAULT NULL,
  `user_agent`  VARCHAR(300) DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_rl_report` (`report_id`),
  INDEX `idx_rl_estudo` (`estudo_id`),
  INDEX `idx_rl_usuario` (`usuario_id`),
  INDEX `idx_rl_acao` (`acao`),
  INDEX `idx_rl_tenant_data` (`tenant_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Auditoria completa de ações no módulo de laudos';

-- ============================================================
-- 5. REPORT_AUTOTEXT — Autotextos / frases rápidas
-- ============================================================
CREATE TABLE IF NOT EXISTS `report_autotext` (
  `id`            INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`     INT(11) UNSIGNED DEFAULT NULL COMMENT 'NULL = global',
  `usuario_id`    INT(11) UNSIGNED DEFAULT NULL COMMENT 'NULL = compartilhado',
  `gatilho`       VARCHAR(100) NOT NULL COMMENT 'Palavra-chave que dispara o autocomplete (ex: torax)',
  `titulo`        VARCHAR(150) NOT NULL COMMENT 'Título exibido na lista de sugestões',
  `conteudo`      TEXT NOT NULL COMMENT 'Texto completo a ser inserido',
  `modalidade`    VARCHAR(20) DEFAULT NULL COMMENT 'Filtro por modalidade (opcional)',
  `especialidade` VARCHAR(100) DEFAULT NULL,
  `ativo`         TINYINT(1) NOT NULL DEFAULT 1,
  `uso_count`     INT(11) NOT NULL DEFAULT 0,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_at_gatilho` (`gatilho`),
  INDEX `idx_at_tenant` (`tenant_id`),
  INDEX `idx_at_ativo` (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Autotextos e autocomplete para o editor de laudos';

-- ============================================================
-- 6. REPORT_FAVORITES — Laudos favoritos do médico
-- ============================================================
CREATE TABLE IF NOT EXISTS `report_favorites` (
  `id`          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id`  INT(11) UNSIGNED NOT NULL COMMENT 'FK → bi_users.id',
  `report_id`   INT(11) UNSIGNED DEFAULT NULL COMMENT 'FK → reports.id',
  `template_id` INT(11) UNSIGNED DEFAULT NULL COMMENT 'FK → report_templates.id',
  `titulo`      VARCHAR(200) DEFAULT NULL COMMENT 'Título personalizado do favorito',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_rf_usuario` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Laudos e templates favoritos por médico';

-- ============================================================
-- 7. REPORT_SIGNATURES — Registro de assinaturas digitais
-- ============================================================
CREATE TABLE IF NOT EXISTS `report_signatures` (
  `id`          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id`   INT(11) UNSIGNED NOT NULL COMMENT 'FK → reports.id',
  `usuario_id`  INT(11) UNSIGNED NOT NULL COMMENT 'FK → bi_users.id',
  `usuario_nome` VARCHAR(150) DEFAULT NULL COMMENT 'Snapshot do nome',
  `crm`         VARCHAR(30) DEFAULT NULL COMMENT 'CRM do médico',
  `hash`        VARCHAR(64) NOT NULL COMMENT 'SHA-256 do conteúdo assinado',
  `conteudo_hash` TEXT COMMENT 'Conteúdo completo que gerou o hash (para auditoria)',
  `ip`          VARCHAR(45) DEFAULT NULL,
  `user_agent`  VARCHAR(300) DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_rs_report` (`report_id`),
  INDEX `idx_rs_usuario` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Registro de assinaturas digitais dos laudos';

-- ============================================================
-- DADOS INICIAIS — Templates padrão por modalidade
-- ============================================================
INSERT INTO `report_templates` (`tenant_id`, `nome`, `modalidade`, `secao_exame`, `secao_tecnica`, `secao_achados`, `secao_conclusao`, `secao_recomendacao`, `ativo`) VALUES
(NULL, 'RX Tórax PA/LAT', 'CR',
 'Radiografia de tórax nas incidências PA e perfil.',
 'Técnica radiológica convencional.',
 'Campos pulmonares livres de infiltrados, consolidações ou derrame pleural. Índice cardiotorácico dentro dos limites da normalidade. Seios costofrênicos livres. Mediastino de aspecto habitual.',
 'Exame dentro dos limites da normalidade.',
 NULL, 1),
(NULL, 'TC Crânio s/ Contraste', 'CT',
 'Tomografia computadorizada do crânio sem contraste.',
 'Cortes axiais de 5mm com reconstruções multiplanares.',
 'Parênquima cerebral com densidade e diferenciação corticosubcortical preservadas. Ausência de lesões focais hipodensas ou hiperdensas. Ventrículos de tamanho e morfologia normais. Sulcos e cisternas pérvios. Estruturas da linha média centradas.',
 'Exame de crânio sem alterações significativas.',
 NULL, 1),
(NULL, 'RM Coluna Lombar', 'MR',
 'Ressonância magnética da coluna lombar.',
 'Sequências T1, T2, STIR nos planos sagital e axial.',
 'Alinhamento vertebral preservado. Corpos vertebrais com sinal e morfologia normais. Discos intervertebrais com altura e sinal preservados. Canal vertebral de dimensões normais. Raízes nervosas sem compressão.',
 'Exame de coluna lombar sem alterações significativas.',
 NULL, 1),
(NULL, 'US Abdome Total', 'US',
 'Ultrassonografia do abdome total.',
 'Exame realizado com transdutor convexo de 3,5 MHz.',
 'Fígado de dimensões normais, ecotextura homogênea, sem lesões focais. Vesícula biliar de paredes finas, sem cálculos. Vias biliares não dilatadas. Pâncreas de aspecto habitual. Baço de dimensões normais. Rins de dimensões e ecogenicidade normais, sem dilatação pielocalicial.',
 'Exame de abdome total sem alterações significativas.',
 NULL, 1),
(NULL, 'Mamografia Bilateral', 'MG',
 'Mamografia bilateral nas incidências CC e MLO.',
 'Técnica digital com compressão adequada.',
 'Mamas com padrão de densidade ACR tipo B (densidade fibroglandular dispersa). Ausência de nódulos, microcalcificações suspeitas ou distorções arquiteturais. Pele e complexo areolomamilar sem alterações.',
 'Mamografia bilateral sem alterações suspeitas. BI-RADS 1.',
 'Seguimento de rotina conforme protocolo.',
 1);

-- ============================================================
-- VALIDAÇÃO
-- ============================================================
SHOW TABLES LIKE 'report%';

-- ============================================================
-- ROLLBACK — Execute para desfazer (ordem inversa)
-- ============================================================
-- DROP TABLE IF EXISTS `report_signatures`;
-- DROP TABLE IF EXISTS `report_favorites`;
-- DROP TABLE IF EXISTS `report_autotext`;
-- DROP TABLE IF EXISTS `report_logs`;
-- DROP TABLE IF EXISTS `report_templates`;
-- DROP TABLE IF EXISTS `report_versions`;
-- DROP TABLE IF EXISTS `reports`;
