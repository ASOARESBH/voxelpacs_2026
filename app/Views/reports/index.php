<?php
/**
 * VOXEL PACS — Editor de Laudo
 * Layout: 3 colunas (Paciente 25% | Editor 50% | Painel Inteligente 25%)
 * Versão: 1.0 — 2026-07-05
 */
$e = $estudo ?? [];
$r = $report ?? null;
$reportId    = $r ? (int)$r->id : 0;
$reportToken = $r ? (string)($r->public_token ?? '') : '';
$estudoId    = (int)($e['id'] ?? 0);
$studyUid    = htmlspecialchars($e['study_instance_uid'] ?? '', ENT_QUOTES);
// Cabeçalho do laudário: projeção de PN sem escrita no estudo.
$paciente    = htmlspecialchars(\App\Helpers\DicomPersonName::displayFromStudy($e) ?: 'Paciente', ENT_QUOTES);
$modalidade  = '';
foreach (array_filter(array_map('trim', explode('\\', $e['modalities'] ?? ''))) as $modItem) {
    $modalidade .= sprintf(
        '<span class="dicom-modality" data-bs-toggle="tooltip" data-bs-placement="top" title="%s">%s</span> ',
        htmlspecialchars(\App\Services\DicomModalityService::description($modItem), ENT_QUOTES),
        htmlspecialchars(\App\Services\DicomModalityService::code($modItem), ENT_QUOTES)
    );
}
$descricaoEfetiva = \App\Services\StudyDescriptionResolver::text($e);
$descricao   = htmlspecialchars($descricaoEfetiva, ENT_QUOTES);
$situacao    = $r ? $r->situacao : 'rascunho';
$somenteLeitura = ($bloqueado ?? false) || in_array($situacao, ['assinado','liberado']);
$csrfToken   = htmlspecialchars($csrf ?? '', ENT_QUOTES);
?>
<!-- ══════════════════════════════════════════════════════════════════════
     BARRA SUPERIOR FIXA DO LAUDO
══════════════════════════════════════════════════════════════════════ -->
<div id="report-topbar">
    <div class="rtb-left">
        <span class="rtb-paciente"><i class="bi bi-person-fill"></i> <?= $paciente ?></span>
        <span class="rtb-exam"><i class="bi bi-activity"></i> <?= $descricao ?> &nbsp;<span class="badge bg-secondary"><?= $modalidade ?></span></span>
    </div>
    <div class="rtb-center">
        <?php if ($bloqueado ?? false): ?>
            <span class="rtb-badge rtb-blocked"><i class="bi bi-lock-fill"></i> Somente Leitura</span>
        <?php elseif ($situacao === 'assinado'): ?>
            <span class="rtb-badge rtb-signed"><i class="bi bi-patch-check-fill"></i> Assinado</span>
        <?php elseif ($situacao === 'liberado'): ?>
            <span class="rtb-badge rtb-released"><i class="bi bi-send-check-fill"></i> Liberado</span>
        <?php else: ?>
            <span class="rtb-badge rtb-editing"><i class="bi bi-pencil-fill"></i> Em Laudo por <?= htmlspecialchars($user->nome ?? '', ENT_QUOTES) ?></span>
        <?php endif; ?>
    </div>
    <div class="rtb-right">
        <span id="rtb-autosave" class="rtb-info"><i class="bi bi-cloud-check"></i> <span id="rtb-saved-at">—</span></span>
        <span id="rtb-timer" class="rtb-info"><i class="bi bi-stopwatch"></i> <span id="rtb-time">00:00</span></span>
        <span class="rtb-info"><i class="bi bi-images"></i> <?= (int)($e['num_instances'] ?? 0) ?> imagens</span>
        <a href="/estudos" class="btn btn-sm btn-outline-secondary ms-2"><i class="bi bi-arrow-left"></i> Worklist</a>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     BLOQUEIO — outro médico editando
══════════════════════════════════════════════════════════════════════ -->
<?php if ($bloqueado ?? false): ?>
<div class="alert alert-warning alert-dismissible mx-3 mt-2 mb-0" role="alert">
    <i class="bi bi-lock-fill me-2"></i>
    Este exame está sendo editado por <strong><?= htmlspecialchars($medico_editando ?? 'outro médico', ENT_QUOTES) ?></strong>
    desde <strong><?= $r ? date('H:i', strtotime($r->bloqueado_em)) : '' ?></strong>.
    Você está em modo somente leitura.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════
     LAYOUT 3 COLUNAS
══════════════════════════════════════════════════════════════════════ -->
<div id="report-layout">

    <!-- ──────────────────────────────────────────────────────────────────
         COLUNA ESQUERDA — Dados do Paciente (25%)
    ────────────────────────────────────────────────────────────────────── -->
    <div id="report-col-left" class="report-col">

        <!-- Card: Paciente -->
        <div class="rcard">
            <div class="rcard-header"><i class="bi bi-person-badge"></i> Paciente</div>
            <div class="rcard-body">
                <div class="rinfo-row"><span class="rinfo-label">Nome</span><span class="rinfo-val"><?= $paciente ?></span></div>
                <div class="rinfo-row"><span class="rinfo-label">Sexo</span><span class="rinfo-val"><?= htmlspecialchars($e['patient_sex'] ?? '—', ENT_QUOTES) ?></span></div>
                <div class="rinfo-row"><span class="rinfo-label">Nascimento</span><span class="rinfo-val"><?= $e['patient_birth_date'] ? date('d/m/Y', strtotime($e['patient_birth_date'])) : '—' ?></span></div>
                <div class="rinfo-row"><span class="rinfo-label">Idade</span><span class="rinfo-val"><?= htmlspecialchars($e['patient_age'] ?? '—', ENT_QUOTES) ?></span></div>
                <div class="rinfo-row"><span class="rinfo-label">Peso</span><span class="rinfo-val"><?= $e['patient_weight'] ? $e['patient_weight'] . ' kg' : '—' ?></span></div>
                <div class="rinfo-row"><span class="rinfo-label">Altura</span><span class="rinfo-val"><?= $e['patient_size'] ? $e['patient_size'] . ' m' : '—' ?></span></div>
                <div class="rinfo-row"><span class="rinfo-label">Prontuário</span><span class="rinfo-val"><?= htmlspecialchars($e['patient_id'] ?? '—', ENT_QUOTES) ?></span></div>
            </div>
        </div>

        <!-- Card: Exame -->
        <div class="rcard">
            <div class="rcard-header"><i class="bi bi-clipboard2-pulse"></i> Exame</div>
            <div class="rcard-body">
                <div class="rinfo-row"><span class="rinfo-label">Modalidade</span><span class="rinfo-val"><?= $modalidade ?></span></div>
                <div class="rinfo-row"><span class="rinfo-label">Especialidade</span><span class="rinfo-val"><?= htmlspecialchars($e['especialidade'] ?? '—', ENT_QUOTES) ?></span></div>
                <div class="rinfo-row"><span class="rinfo-label">Descrição</span><span class="rinfo-val"><?= $descricao ?></span></div>
                <div class="rinfo-row"><span class="rinfo-label">Data</span><span class="rinfo-val"><?= $e['study_date'] ? date('d/m/Y', strtotime($e['study_date'])) : '—' ?></span></div>
                <div class="rinfo-row"><span class="rinfo-label">Hora</span><span class="rinfo-val"><?= $e['study_time'] ? substr($e['study_time'], 0, 5) : '—' ?></span></div>
                <div class="rinfo-row"><span class="rinfo-label">Imagens</span><span class="rinfo-val"><?= (int)($e['num_instances'] ?? 0) ?></span></div>
                <div class="rinfo-row"><span class="rinfo-label">Séries</span><span class="rinfo-val"><?= (int)($e['num_series'] ?? 0) ?></span></div>
                <div class="rinfo-row"><span class="rinfo-label">Fabricante</span><span class="rinfo-val"><?= htmlspecialchars($e['manufacturer'] ?? '—', ENT_QUOTES) ?></span></div>
                <div class="rinfo-row"><span class="rinfo-label">Equipamento</span><span class="rinfo-val"><?= htmlspecialchars($e['station_name'] ?? '—', ENT_QUOTES) ?></span></div>
                <div class="rinfo-row"><span class="rinfo-label">Instituição</span><span class="rinfo-val"><?= htmlspecialchars($e['institution_name'] ?? '—', ENT_QUOTES) ?></span></div>
                <div class="rinfo-row"><span class="rinfo-label">Solicitante</span><span class="rinfo-val"><?= htmlspecialchars(\App\Helpers\DicomPersonName::format($e['referring_physician_name'] ?? null) ?: '—', ENT_QUOTES) ?></span></div>
                <div class="rinfo-row"><span class="rinfo-label">Accession</span><span class="rinfo-val small"><?= htmlspecialchars($e['accession_number'] ?? '—', ENT_QUOTES) ?></span></div>
                <div class="rinfo-row"><span class="rinfo-label">Study UID</span><span class="rinfo-val small text-truncate" title="<?= $studyUid ?>"><?= substr($studyUid, 0, 20) ?>…</span></div>
            </div>
        </div>

        <!-- Card: Ações Viewer -->
        <div class="rcard">
            <div class="rcard-header"><i class="bi bi-display"></i> Visualizador</div>
            <div class="rcard-body d-grid gap-2">
                <a href="/open/<?= urlencode($studyUid) ?>" target="_blank" class="btn btn-primary btn-sm">
                    <i class="bi bi-eye-fill me-1"></i> Abrir Viewer
                </a>
                <button class="btn btn-outline-info btn-sm" onclick="syncViewer()">
                    <i class="bi bi-arrow-repeat me-1"></i> Sincronizar Viewer
                </button>
            </div>
        </div>

        <!-- Card: Exames Anteriores -->
        <?php if (!empty($exames_anteriores)): ?>
        <div class="rcard">
            <div class="rcard-header"><i class="bi bi-clock-history"></i> Exames Anteriores</div>
            <div class="rcard-body p-0">
                <?php foreach ($exames_anteriores as $ea): ?>
                <div class="rexame-anterior" onclick="window.open('/reports/<?= urlencode($ea['study_instance_uid']) ?>', '_blank')">
                    <div class="d-flex justify-content-between align-items-center">
                        <span>
                            <?php foreach (array_filter(array_map('trim', explode('\\', $ea['modalities'] ?? ''))) as $eaMod): ?>
                                <span class="badge bg-secondary dicom-modality" data-bs-toggle="tooltip" data-bs-placement="top"
                                      title="<?= htmlspecialchars(\App\Services\DicomModalityService::description($eaMod), ENT_QUOTES) ?>"><?= htmlspecialchars(\App\Services\DicomModalityService::code($eaMod), ENT_QUOTES) ?></span>
                            <?php endforeach; ?>
                        </span>
                        <small class="text-muted"><?= $ea['study_date'] ? date('d/m/Y', strtotime($ea['study_date'])) : '' ?></small>
                    </div>
                    <div class="small mt-1"><?= htmlspecialchars(\App\Services\StudyDescriptionResolver::text($ea, '—'), ENT_QUOTES) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /col-left -->

    <!-- ──────────────────────────────────────────────────────────────────
         COLUNA CENTRAL — Editor Médico (50%)
    ────────────────────────────────────────────────────────────────────── -->
    <div id="report-col-center" class="report-col">

        <!-- Toolbar -->
        <div id="report-toolbar" class="<?= $somenteLeitura ? 'd-none' : '' ?>">
            <div class="rtb-group">
                <button class="rtb-btn" onclick="execCmd('bold')" title="Negrito (Ctrl+B)"><i class="bi bi-type-bold"></i></button>
                <button class="rtb-btn" onclick="execCmd('italic')" title="Itálico (Ctrl+I)"><i class="bi bi-type-italic"></i></button>
                <button class="rtb-btn" onclick="execCmd('underline')" title="Sublinhado (Ctrl+U)"><i class="bi bi-type-underline"></i></button>
            </div>
            <div class="rtb-group">
                <button class="rtb-btn" onclick="execCmd('insertUnorderedList')" title="Lista"><i class="bi bi-list-ul"></i></button>
                <button class="rtb-btn" onclick="execCmd('insertOrderedList')" title="Lista Numerada"><i class="bi bi-list-ol"></i></button>
                <button class="rtb-btn" onclick="insertTable()" title="Tabela"><i class="bi bi-table"></i></button>
            </div>
            <div class="rtb-group">
                <button class="rtb-btn" onclick="execCmd('undo')" title="Desfazer (Ctrl+Z)"><i class="bi bi-arrow-counterclockwise"></i></button>
                <button class="rtb-btn" onclick="execCmd('redo')" title="Refazer (Ctrl+Y)"><i class="bi bi-arrow-clockwise"></i></button>
            </div>
            <div class="rtb-group">
                <select id="font-size-sel" class="rtb-select" onchange="execCmd('fontSize', this.value)" title="Tamanho">
                    <option value="2">Pequeno</option>
                    <option value="3" selected>Normal</option>
                    <option value="4">Grande</option>
                    <option value="5">Maior</option>
                </select>
            </div>
            <div class="rtb-group ms-auto">
                <button class="rtb-btn text-info" onclick="openTemplatesModal()" title="Templates"><i class="bi bi-file-earmark-text"></i> Templates</button>
            </div>
        </div>

        <!-- Corpo clínico livre: o médico digita, cola ou aplica uma máscara sem
             cabeçalhos obrigatórios. Templates podem trazer títulos próprios. -->
        <div id="report-sections" class="report-free-body-wrap">
            <?php
            $corpoLaudo = (string) ($r->corpo_laudo ?? '');
            if (trim($corpoLaudo) === '' && $r) {
                $blocosLegados = array_filter([
                    (string) ($r->secao_exame ?? ''),
                    (string) ($r->secao_tecnica ?? ''),
                    (string) ($r->secao_achados ?? ''),
                    (string) ($r->secao_conclusao ?? ''),
                    (string) ($r->secao_recomendacao ?? ''),
                ], static fn($valor) => trim(strip_tags($valor)) !== '');
                $corpoLaudo = implode('<br><br>', $blocosLegados);
            }
            ?>
            <div class="report-editor report-editor-free <?= $somenteLeitura ? 'readonly' : '' ?>"
                 id="editor-corpo"
                 contenteditable="<?= $somenteLeitura ? 'false' : 'true' ?>"
                 data-placeholder="Redija, cole ou aplique uma máscara de laudo..."> <?= htmlspecialchars_decode(htmlspecialchars($corpoLaudo, ENT_QUOTES)) ?></div>
        </div>

            <!-- Seção: ASSINATURA -->
            <div class="rsection" id="sec-assinatura">
                <div class="rsection-header" onclick="toggleSection('assinatura')">
                    <span><i class="bi bi-chevron-down" id="ico-assinatura"></i> ASSINATURA</span>
                </div>
                <div class="rsection-body" id="body-assinatura">
                    <div class="rcard-body">
                        <?php if ($situacao === 'assinado' || $situacao === 'liberado'): ?>
                            <div class="alert alert-success mb-0">
                                <i class="bi bi-patch-check-fill me-2"></i>
                                Laudo assinado por <strong><?= htmlspecialchars($r->medico_nome ?? '', ENT_QUOTES) ?></strong>
                                (CRM: <?= htmlspecialchars($r->assinatura_crm ?? '—', ENT_QUOTES) ?>)
                                em <?= $r->assinado_em ? date('d/m/Y H:i', strtotime($r->assinado_em)) : '—' ?>
                                <br><small class="text-muted">Hash: <?= htmlspecialchars($r->assinatura_hash ?? '', ENT_QUOTES) ?></small>
                            </div>
                        <?php else: ?>
                            <div class="text-muted small"><i class="bi bi-info-circle"></i> Laudo ainda não assinado.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div><!-- /report-sections -->

        <!-- Barra de Ações -->
        <?php if (!$somenteLeitura): ?>
        <div id="report-actions">
            <button class="btn btn-outline-secondary btn-sm" onclick="saveReport(false)">
                <i class="bi bi-floppy me-1"></i> Salvar Rascunho
            </button>
            <button class="btn btn-success btn-sm" onclick="saveReport(true)">
                <i class="bi bi-cloud-upload me-1"></i> Salvar
            </button>
            <?php if ($reportId && in_array($situacao, ['rascunho'])): ?>
            <button class="btn btn-warning btn-sm" onclick="openSignModal()">
                <i class="bi bi-pen-fill me-1"></i> Assinar Laudo
            </button>
            <?php endif; ?>
            <?php if ($reportToken !== ''): ?>
            <a href="/reports/r/<?= rawurlencode($reportToken) ?>/pdf" target="_blank" class="btn btn-outline-info btn-sm">
                <i class="bi bi-file-pdf me-1"></i> PDF
            </a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div id="report-actions">
            <?php if ($reportToken !== ''): ?>
            <a href="/reports/r/<?= rawurlencode($reportToken) ?>/pdf" target="_blank" class="btn btn-outline-info btn-sm">
                <i class="bi bi-file-pdf me-1"></i> Visualizar PDF
            </a>
            <a href="/reports/r/<?= rawurlencode($reportToken) ?>/pdf?download=1" class="btn btn-secondary btn-sm">
                <i class="bi bi-download me-1"></i> Baixar PDF
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Autocomplete dropdown -->
        <div id="autotext-dropdown" class="autotext-dropdown d-none"></div>

    </div><!-- /col-center -->

    <!-- ──────────────────────────────────────────────────────────────────
         COLUNA DIREITA — Painel Inteligente (25%)
    ────────────────────────────────────────────────────────────────────── -->
    <div id="report-col-right" class="report-col">

        <!-- Autotextos -->
        <div class="rcard">
            <div class="rcard-header"><i class="bi bi-lightning-charge"></i> Autotextos</div>
            <div class="rcard-body p-0">
                <div class="px-2 pt-2">
                    <input type="text" id="autotext-search" class="form-control form-control-sm"
                           placeholder="Buscar autotexto..." oninput="searchAutotext(this.value)">
                </div>
                <div id="autotext-list" class="autotext-list">
                    <?php foreach ($autotexts as $at): ?>
                    <div class="autotext-item" onclick="insertAutotext(<?= (int)$at['id'] ?>, <?= htmlspecialchars(json_encode($at['conteudo']), ENT_QUOTES) ?>)">
                        <div class="at-trigger"><?= htmlspecialchars($at['gatilho'], ENT_QUOTES) ?></div>
                        <div class="at-titulo"><?= htmlspecialchars($at['titulo'], ENT_QUOTES) ?></div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($autotexts)): ?>
                    <div class="text-muted small px-2 py-2">Nenhum autotexto cadastrado.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Histórico -->
        <div class="rcard">
            <div class="rcard-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history"></i> Histórico</span>
                <?php if ($reportId): ?>
                <button class="btn btn-link btn-sm p-0 text-info" onclick="loadHistory(<?= $reportId ?>)">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
                <?php endif; ?>
            </div>
            <div id="history-list" class="rcard-body p-0">
                <?php foreach (array_slice($versoes ?? [], 0, 5) as $v): ?>
                <div class="rhistory-item">
                    <div class="d-flex justify-content-between">
                        <span class="badge bg-<?= $v['acao'] === 'assinado' ? 'success' : 'secondary' ?>"><?= htmlspecialchars($v['acao'], ENT_QUOTES) ?></span>
                        <small class="text-muted">v<?= $v['versao'] ?></small>
                    </div>
                    <div class="small mt-1"><?= htmlspecialchars($v['usuario_nome'] ?? '—', ENT_QUOTES) ?></div>
                    <div class="small text-muted"><?= htmlspecialchars($v['data_fmt'] ?? '', ENT_QUOTES) ?></div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($versoes)): ?>
                <div class="text-muted small px-2 py-2">Nenhuma versão registrada.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Checklist -->
        <div class="rcard">
            <div class="rcard-header"><i class="bi bi-check2-square"></i> Checklist</div>
            <div class="rcard-body">
                <div class="form-check form-check-sm">
                    <input class="form-check-input" type="checkbox" id="chk-corpo" onchange="updateChecklist()">
                    <label class="form-check-label" for="chk-corpo">Texto do laudo preenchido</label>
                </div>
                <div class="form-check form-check-sm">
                    <input class="form-check-input" type="checkbox" id="chk-assinatura" disabled>
                    <label class="form-check-label" for="chk-assinatura">Laudo assinado</label>
                </div>
                <div class="progress mt-2" style="height:4px">
                    <div id="checklist-progress" class="progress-bar bg-success" style="width:0%"></div>
                </div>
            </div>
        </div>

        <!-- Alertas -->
        <div class="rcard" id="card-alertas">
            <div class="rcard-header"><i class="bi bi-exclamation-triangle"></i> Alertas</div>
            <div class="rcard-body" id="alertas-body">
                <?php
                $prioridade = $e['prioridade'] ?? 'normal';
                if ($prioridade === 'urgente' || $prioridade === 'critico'):
                ?>
                <div class="alert alert-danger py-1 px-2 mb-1 small">
                    <i class="bi bi-exclamation-octagon-fill me-1"></i>
                    Prioridade: <strong><?= strtoupper($prioridade) ?></strong>
                </div>
                <?php endif; ?>
                <div class="text-muted small">Sem alertas clínicos.</div>
            </div>
        </div>

        <!-- IA — Estrutura preparada -->
        <div class="rcard">
            <div class="rcard-header"><i class="bi bi-robot"></i> IA <span class="badge bg-secondary ms-1">Em breve</span></div>
            <div class="rcard-body">
                <div class="text-muted small">
                    <i class="bi bi-info-circle me-1"></i>
                    Sugestões automáticas por IA serão integradas em versão futura.
                </div>
            </div>
        </div>

        <!-- Observações -->
        <div class="rcard">
            <div class="rcard-header"><i class="bi bi-chat-text"></i> Observações</div>
            <div class="rcard-body">
                <textarea id="obs-textarea" class="form-control form-control-sm" rows="3"
                          placeholder="Observações internas (não aparecem no laudo)..."
                          <?= $somenteLeitura ? 'disabled' : '' ?>></textarea>
            </div>
        </div>

    </div><!-- /col-right -->

</div><!-- /report-layout -->

<!-- ══════════════════════════════════════════════════════════════════════
     MODAL — Assinar Laudo
══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="signModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-light border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="bi bi-pen-fill me-2"></i>Assinar Laudo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    Ao assinar, o laudo será finalizado e não poderá mais ser editado.
                    Confirme com sua senha de acesso.
                </p>
                <div class="mb-3">
                    <label class="form-label">Senha</label>
                    <input type="password" id="sign-senha" class="form-control bg-dark text-light border-secondary"
                           placeholder="Digite sua senha..." autocomplete="current-password">
                </div>
                <div id="sign-error" class="alert alert-danger d-none py-2 small"></div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning" onclick="confirmSign()">
                    <i class="bi bi-pen-fill me-1"></i> Confirmar Assinatura
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     MODAL — Templates
══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="templatesModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content bg-dark text-light border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Templates de Laudo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="d-flex gap-2 flex-wrap" id="tpl-filter-btns">
                        <button class="btn btn-sm btn-outline-secondary tpl-filter active" data-mod="">Todos</button>
                        <?php
                        $mods = array_unique(array_column($templates, 'modalidade'));
                        sort($mods);
                        foreach ($mods as $mod):
                            if ($mod):
                        ?>
                        <button class="btn btn-sm btn-outline-secondary tpl-filter" data-mod="<?= htmlspecialchars($mod, ENT_QUOTES) ?>"><?= htmlspecialchars($mod, ENT_QUOTES) ?></button>
                        <?php endif; endforeach; ?>
                    </div>
                </div>
                <div id="tpl-list" class="row g-2">
                    <?php foreach ($templates as $tpl): ?>
                    <div class="col-md-6 tpl-item" data-mod="<?= htmlspecialchars($tpl['modalidade'] ?? '', ENT_QUOTES) ?>">
                        <div class="rcard tpl-card" onclick="applyTemplate(<?= (int)$tpl['id'] ?>)">
                            <div class="rcard-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong class="small"><?= htmlspecialchars($tpl['nome'], ENT_QUOTES) ?></strong>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($tpl['modalidade'] ?? '—', ENT_QUOTES) ?></span>
                                </div>
                                <div class="text-muted small mt-1"><?= htmlspecialchars($tpl['especialidade'] ?? '', ENT_QUOTES) ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($templates)): ?>
                    <div class="col-12 text-muted small">Nenhum template disponível.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     CSS INLINE DO MÓDULO REPORTS
══════════════════════════════════════════════════════════════════════ -->
<style>
/* ── Topbar do Laudo ── */
#report-topbar {
    display: flex;
    align-items: center;
    gap: 1rem;
    background: var(--pacs-header-bg);
    border-bottom: 1px solid var(--pacs-border);
    padding: .5rem 1rem;
    position: sticky;
    top: 0;
    z-index: 100;
    flex-wrap: wrap;
}
.rtb-left { display: flex; align-items: center; gap: 1rem; flex: 1; min-width: 0; }
.rtb-center { display: flex; align-items: center; }
.rtb-right { display: flex; align-items: center; gap: .75rem; flex-shrink: 0; }
.rtb-paciente { font-weight: 700; color: var(--pacs-primary); font-size: .9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rtb-exam { color: var(--pacs-text-muted); font-size: .8rem; }
.rtb-info { font-size: .75rem; color: var(--pacs-text-muted); white-space: nowrap; }
.rtb-badge { font-size: .75rem; padding: .2rem .6rem; border-radius: 20px; font-weight: 600; }
.rtb-editing { background: rgba(255,202,40,.15); color: #ffca28; border: 1px solid #ffca28; }
.rtb-signed  { background: rgba(105,240,174,.15); color: #69f0ae; border: 1px solid #69f0ae; }
.rtb-released{ background: rgba(79,195,247,.15); color: #4fc3f7; border: 1px solid #4fc3f7; }
.rtb-blocked { background: rgba(255,82,82,.15); color: #ff5252; border: 1px solid #ff5252; }

/* ── Layout 3 colunas ── */
#report-layout {
    display: grid;
    grid-template-columns: 25% 50% 25%;
    height: calc(100vh - 110px);
    overflow: hidden;
}
.report-col {
    overflow-y: auto;
    border-right: 1px solid var(--pacs-border);
    padding: .5rem;
}
#report-col-right { border-right: none; }

/* ── Cards ── */
.rcard {
    background: var(--pacs-card-bg);
    border: 1px solid var(--pacs-border);
    border-radius: 6px;
    margin-bottom: .5rem;
    overflow: hidden;
}
.rcard-header {
    background: rgba(255,255,255,.04);
    padding: .4rem .75rem;
    font-size: .75rem;
    font-weight: 700;
    color: var(--pacs-primary);
    text-transform: uppercase;
    letter-spacing: .5px;
    cursor: pointer;
    user-select: none;
}
.rcard-body { padding: .5rem .75rem; }

/* ── Info rows ── */
.rinfo-row {
    display: flex;
    gap: .5rem;
    padding: .2rem 0;
    border-bottom: 1px solid rgba(255,255,255,.04);
    font-size: .78rem;
}
.rinfo-label { color: var(--pacs-text-muted); min-width: 80px; flex-shrink: 0; }
.rinfo-val { color: var(--pacs-text); word-break: break-word; }

/* ── Estruturas legadas (laudos antigos) ── */
.rsection { margin-bottom: .4rem; border: 1px solid var(--pacs-border); border-radius: 6px; overflow: hidden; }
.rsection-header {
    background: rgba(15,52,96,.6);
    padding: .5rem .75rem;
    font-size: .78rem;
    font-weight: 700;
    color: var(--pacs-primary);
    cursor: pointer;
    user-select: none;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.rsection-header:hover { background: rgba(15,52,96,.9); }
.rsection-body { padding: 0; }

/* ── Editor contenteditable ── */
.report-editor {
    min-height: 120px;
    padding: .75rem;
    background: var(--pacs-input-bg);
    color: var(--pacs-text);
    font-size: .85rem;
    line-height: 1.6;
    outline: none;
    border-top: 1px solid var(--pacs-border);
    transition: border-color .2s;
}
.report-editor:focus { border-color: var(--pacs-primary); }
.report-editor.readonly { opacity: .7; cursor: default; }
.report-editor:empty:before {
    content: attr(data-placeholder);
    color: var(--pacs-text-muted);
    pointer-events: none;
}
/* Corpo livre: único campo clínico para digitação, colagem e máscaras. */
.report-free-body-wrap {
    min-height: clamp(520px, calc(100vh - 16rem), 900px);
}
.report-editor-free {
    min-height: clamp(520px, calc(100vh - 16rem), 900px);
    border: 1px solid var(--pacs-border);
    border-radius: 6px;
    overflow-wrap: anywhere;
    white-space: normal;
}
.report-editor-free:focus {
    box-shadow: 0 0 0 2px rgba(79,195,247,.12);
}
.report-editor-free.readonly {
    min-height: auto;
    background: transparent;
    border-color: transparent;
}

/* ── Toolbar ── */
#report-toolbar {
    display: flex;
    align-items: center;
    gap: .25rem;
    background: var(--pacs-card-bg);
    border: 1px solid var(--pacs-border);
    border-radius: 6px;
    padding: .35rem .5rem;
    margin-bottom: .4rem;
    flex-wrap: wrap;
}
.rtb-group { display: flex; gap: .2rem; }
.rtb-btn {
    background: none;
    border: 1px solid transparent;
    color: var(--pacs-text);
    padding: .2rem .45rem;
    border-radius: 4px;
    cursor: pointer;
    font-size: .85rem;
    transition: background .15s, border-color .15s;
}
.rtb-btn:hover { background: rgba(255,255,255,.08); border-color: var(--pacs-border); }
.rtb-select {
    background: var(--pacs-input-bg);
    color: var(--pacs-text);
    border: 1px solid var(--pacs-border);
    border-radius: 4px;
    padding: .15rem .3rem;
    font-size: .78rem;
}

/* ── Barra de ações ── */
#report-actions {
    display: flex;
    gap: .5rem;
    align-items: center;
    padding: .5rem;
    background: var(--pacs-card-bg);
    border: 1px solid var(--pacs-border);
    border-radius: 6px;
    margin-top: .4rem;
    flex-wrap: wrap;
}

/* ── Autotexto dropdown ── */
.autotext-dropdown {
    position: absolute;
    background: var(--pacs-card-bg);
    border: 1px solid var(--pacs-primary);
    border-radius: 6px;
    z-index: 1000;
    min-width: 280px;
    max-height: 200px;
    overflow-y: auto;
    box-shadow: 0 4px 20px rgba(0,0,0,.5);
}
.autotext-dropdown-item {
    padding: .4rem .75rem;
    cursor: pointer;
    font-size: .8rem;
    border-bottom: 1px solid var(--pacs-border);
}
.autotext-dropdown-item:hover { background: var(--pacs-row-hover); }
.autotext-dropdown-item strong { color: var(--pacs-primary); }

/* ── Autotexto sidebar ── */
.autotext-list { max-height: 200px; overflow-y: auto; }
.autotext-item {
    padding: .4rem .75rem;
    cursor: pointer;
    border-bottom: 1px solid var(--pacs-border);
    transition: background .15s;
}
.autotext-item:hover { background: var(--pacs-row-hover); }
.at-trigger { font-size: .7rem; color: var(--pacs-primary); font-weight: 700; text-transform: uppercase; }
.at-titulo { font-size: .78rem; color: var(--pacs-text); }

/* ── Histórico ── */
.rhistory-item {
    padding: .4rem .75rem;
    border-bottom: 1px solid var(--pacs-border);
    font-size: .78rem;
}
.rhistory-item:last-child { border-bottom: none; }

/* ── Exames anteriores ── */
.rexame-anterior {
    padding: .4rem .75rem;
    border-bottom: 1px solid var(--pacs-border);
    cursor: pointer;
    transition: background .15s;
}
.rexame-anterior:hover { background: var(--pacs-row-hover); }

/* ── Template card ── */
.tpl-card { cursor: pointer; transition: border-color .15s; }
.tpl-card:hover { border-color: var(--pacs-primary) !important; }
</style>

<!-- ══════════════════════════════════════════════════════════════════════
     JAVASCRIPT DO MÓDULO REPORTS
══════════════════════════════════════════════════════════════════════ -->
<script>
(function() {
    'use strict';

    // ── Constantes do laudo ──────────────────────────────────────────────
    const REPORT_ID    = <?= $reportId ?>;
    const ESTUDO_ID    = <?= $estudoId ?>;
    const CSRF         = '<?= $csrfToken ?>';
    let TEMPLATE_ID_ATUAL = <?= (int) ($r->template_id ?? 0) ?>;
    const READONLY     = <?= $somenteLeitura ? 'true' : 'false' ?>;
    const AUTOSAVE_SEC = 30;
    const MEDICO_ID    = <?= (int) ($medicoIdLogado ?? 0) ?>;
    const STUDY_DESC   = '<?= htmlspecialchars(strtoupper($descricaoEfetiva), ENT_QUOTES) ?>';
    const MODALIDADE   = '<?= htmlspecialchars(explode('\\', $e['modalities'] ?? '')[0] ?? '', ENT_QUOTES) ?>';

    // ── Estado ──────────────────────────────────────────────────────────
    let timerSec    = 0;
    let autosaveInt = null;
    let timerInt    = null;
    let lastFocused = null; // Último editor com foco

    // ── Inicialização ────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function() {
        if (!READONLY) {
            startTimer();
            startAutosave();
        }
        initEditorFocus();
        initChecklist();
    });

    // ── Timer de laudo ───────────────────────────────────────────────────
    function startTimer() {
        timerInt = setInterval(function() {
            timerSec++;
            const m = String(Math.floor(timerSec / 60)).padStart(2, '0');
            const s = String(timerSec % 60).padStart(2, '0');
            const el = document.getElementById('rtb-time');
            if (el) el.textContent = m + ':' + s;
        }, 1000);
    }

    // ── Autosave ─────────────────────────────────────────────────────────
    function startAutosave() {
        autosaveInt = setInterval(function() {
            saveReport(false);
        }, AUTOSAVE_SEC * 1000);
    }

    // ── Foco nos editores ────────────────────────────────────────────────
    function initEditorFocus() {
        document.querySelectorAll('.report-editor').forEach(function(ed) {
            ed.addEventListener('focus', function() { lastFocused = ed; });
            ed.addEventListener('input', function() {
                initChecklist();
                triggerAutocomplete(ed);
            });
        });
    }

    // ── Coleta conteúdo dos editores ─────────────────────────────────────
    function getEditorContent(section) {
        const el = document.getElementById('editor-' + section);
        return el ? el.innerHTML : '';
    }

    function getAllContent() {
        return {
            corpo_laudo: getEditorContent('corpo'),
            template_id: TEMPLATE_ID_ATUAL || null
        };
    }

    // ── Salvar laudo ─────────────────────────────────────────────────────
    window.saveReport = function(isManual) {
        if (READONLY || !REPORT_ID) return;

        const payload = Object.assign({
            id:              REPORT_ID,
            estudo_id:       ESTUDO_ID,
            csrf:            CSRF,
            is_manual:       isManual,
            tempo_decorrido: timerSec
        }, getAllContent());

        fetch('/reports/save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(function(data) {
            const el = document.getElementById('rtb-saved-at');
            if (el) {
                el.textContent = data.ok ? 'Salvo às ' + data.saved_at : 'Erro ao salvar';
                el.style.color = data.ok ? '#69f0ae' : '#ff5252';
            }
            if (isManual && data.ok) {
                showToast('Laudo salvo com sucesso!', 'success');
            }
        })
        .catch(function() {
            const el = document.getElementById('rtb-saved-at');
            if (el) { el.textContent = 'Falha na conexão'; el.style.color = '#ff5252'; }
        });
    };

    // ── Assinar laudo ────────────────────────────────────────────────────
    window.openSignModal = function() {
        document.getElementById('sign-senha').value = '';
        document.getElementById('sign-error').classList.add('d-none');
        new bootstrap.Modal(document.getElementById('signModal')).show();
    };

    window.confirmSign = function() {
        const senha = document.getElementById('sign-senha').value;
        if (!senha) {
            showSignError('Informe a senha.');
            return;
        }

        const payload = Object.assign({
            id:        REPORT_ID,
            estudo_id: ESTUDO_ID,
            csrf:      CSRF,
            senha:     senha
        }, getAllContent());

        fetch('/reports/sign', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(function(data) {
            if (data.ok) {
                bootstrap.Modal.getInstance(document.getElementById('signModal')).hide();
                showToast('Laudo assinado com sucesso!', 'success');
                if (typeof window.atualizarBadgesTopbar === 'function') window.atualizarBadgesTopbar();
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                showSignError(data.msg || 'Erro ao assinar.');
            }
        })
        .catch(function() { showSignError('Falha na conexão.'); });
    };

    function showSignError(msg) {
        const el = document.getElementById('sign-error');
        el.textContent = msg;
        el.classList.remove('d-none');
    }

    // ── Templates ────────────────────────────────────────────────────────
    // ── Templates / Máscaras ─────────────────────────────────────────────────
    // Auto-carregamento por Study Description ao abrir o laudo
    document.addEventListener('DOMContentLoaded', function() {
        if (!READONLY && STUDY_DESC && MEDICO_ID) {
            setTimeout(function() { autoCarregarTemplate(); }, 800);
        }
    });

    function autoCarregarTemplate() {
        var url = '/api/templates/auto?study_description=' + encodeURIComponent(STUDY_DESC)
                + '&medico_id=' + MEDICO_ID;
        fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok && data.template) {
                var t = data.template;
                var editor = document.getElementById('editor-corpo');
                var temConteudo = editor && editor.innerText.trim().length > 3;
                if (!temConteudo) {
                    aplicarTemplate(t);
                    showToast('Mascara "' + t.nome + '" carregada automaticamente.', 'success');
                }
            }
        })
        .catch(function() {});
    }

    function aplicarTemplate(t) {
        // O título e as seções explícitas acompanham o conteúdo até a impressão.
        // Isso preserva a redação livre do médico, mas mantém a estrutura clínica
        // da Máscara quando ela foi escolhida para o laudo.
        var secoes = [
            { rotulo: 'TÉCNICA', conteudo: t.secao_tecnica },
            { rotulo: 'ACHADOS', conteudo: t.secao_achados },
            { rotulo: 'IMPRESSÃO', conteudo: t.secao_conclusao }
        ].filter(function(secao) {
            return String(secao.conteudo || '').replace(/<[^>]+>/g, '').trim() !== '';
        });
        var corpo = secoes.length
            ? secoes.map(function(secao) {
                return '<p><strong>' + secao.rotulo + '</strong></p>' + secao.conteudo;
            }).join('<p><br></p>')
            : (t.corpo_laudo || [
                t.secao_exame,
                t.secao_recomendacao
            ].filter(function(html) {
                return String(html || '').replace(/<[^>]+>/g, '').trim() !== '';
            }).join('<br><br>'));
        TEMPLATE_ID_ATUAL = parseInt(t.id, 10) || TEMPLATE_ID_ATUAL;
        setEditorContent('corpo', corpo);
        initChecklist();
        if (!READONLY) saveReport(false);
    }

    window.openTemplatesModal = function() {
        var panel = document.getElementById('mascarasBuscaPanel');
        if (!panel) { criarPainelMascaras(); return; }
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        if (panel.style.display === 'block') buscarMascaras('');
    };

    function criarPainelMascaras() {
        var html = '<div id="mascarasBuscaPanel" style="position:fixed;top:60px;right:1rem;z-index:9000;width:380px;max-height:70vh;overflow-y:auto;background:var(--pacs-card-bg,#1e2330);border:1px solid var(--pacs-border,#2d3244);border-radius:10px;box-shadow:0 8px 32px rgba(0,0,0,.5);">'
            + '<div style="padding:.85rem 1rem;border-bottom:1px solid var(--pacs-border,#2d3244);display:flex;justify-content:space-between;align-items:center;">'
            + '<span style="font-size:.88rem;font-weight:700;"><i class=\"fa fa-layer-group me-1\"></i> Mascaras</span>'
            + '<button onclick="document.getElementById('mascarasBuscaPanel').style.display='none'" style="background:transparent;border:none;color:var(--pacs-text-muted);cursor:pointer;"><i class=\"fa fa-xmark\"></i></button>'
            + '</div>'
            + '<div style="padding:.75rem;">'
            + '<input type="text" id="mascarasBuscaInput" placeholder="Buscar mascara..." oninput="buscarMascaras(this.value)"'
            + ' style="width:100%;padding:.4rem .7rem;background:var(--pacs-input-bg,#252b3b);border:1px solid var(--pacs-border,#3a3f4b);border-radius:5px;color:var(--pacs-text,#e2e8f0);font-size:.82rem;outline:none;">'
            + '</div>'
            + '<div id="mascarasBuscaResultados" style="padding:0 .75rem .75rem;"></div>'
            + '</div>';
        document.body.insertAdjacentHTML('beforeend', html);
        buscarMascaras('');
    }

    function buscarMascaras(q) {
        var url = '/api/templates/buscar?medico_id=' + MEDICO_ID
                + '&modalidade=' + encodeURIComponent(MODALIDADE)
                + '&q=' + encodeURIComponent(q);
        fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var el = document.getElementById('mascarasBuscaResultados');
            if (!el) return;
            if (!data.ok || !data.templates.length) {
                el.innerHTML = '<p style="font-size:.78rem;color:var(--pacs-text-muted);text-align:center;padding:.75rem;">Nenhuma mascara encontrada.</p>';
                return;
            }
            el.innerHTML = data.templates.map(function(t) {
                var badge = t.modalidade ? '<span style="background:rgba(26,86,219,.2);color:#60a5fa;padding:.1rem .4rem;border-radius:3px;font-size:.68rem;">' + escHtml(t.modalidade) + '</span> ' : '';
                var tagBadge = t.study_description_tag ? '<span style="color:#c084fc;font-size:.68rem;"><i class=\"fa fa-tag\"></i> ' + escHtml(t.study_description_tag) + '</span>' : '';
                return '<div onclick="aplicarTemplatePorId(' + t.id + ')" style="cursor:pointer;padding:.6rem .75rem;border-radius:6px;margin-bottom:.35rem;background:rgba(26,86,219,.06);border:1px solid rgba(26,86,219,.15);">'
                    + '<div style="font-size:.83rem;font-weight:600;">' + escHtml(t.nome) + '</div>'
                    + '<div style="font-size:.7rem;color:var(--pacs-text-muted);margin-top:.15rem;">' + badge + tagBadge + '</div>'
                    + '</div>';
            }).join('');
        })
        .catch(function() {});
    }

    function aplicarTemplatePorId(id) {
        fetch('/reports/template?id=' + id)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok) { showToast('Erro ao carregar mascara.', 'danger'); return; }
            aplicarTemplate(data.template);
            var panel = document.getElementById('mascarasBuscaPanel');
            if (panel) panel.style.display = 'none';
            showToast('Mascara "' + data.template.nome + '" aplicada!', 'success');
        });
    }

    window.applyTemplate = aplicarTemplatePorId;

    function setEditorContent(section, html) {
        const el = document.getElementById('editor-' + section);
        if (el) el.innerHTML = html;
    }

    // ── Autocomplete ─────────────────────────────────────────────────────
    let autocompleteTimeout = null;
    function triggerAutocomplete(editor) {
        clearTimeout(autocompleteTimeout);
        autocompleteTimeout = setTimeout(function() {
            const text = editor.innerText || '';
            const words = text.split(/\s+/);
            const lastWord = words[words.length - 1];
            if (lastWord.length >= 3) {
                fetchAutocomplete(lastWord, editor);
            } else {
                hideAutocomplete();
            }
        }, 300);
    }

    function fetchAutocomplete(q, editor) {
        fetch('/api/reports/autotext?q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(function(data) {
            const items = Array.isArray(data) ? data : (data.items || []);
            if (!items.length) { hideAutocomplete(); return; }
            showAutocompleteDropdown(items, editor);
        })
        .catch(hideAutocomplete);
    }

    function showAutocompleteDropdown(items, editor) {
        const dd = document.getElementById('autotext-dropdown');
        dd.innerHTML = '';
        items.forEach(function(item) {
            const div = document.createElement('div');
            div.className = 'autotext-dropdown-item';
            div.innerHTML = '<strong>' + escHtml(item.gatilho) + '</strong> — ' + escHtml(item.titulo);
            div.onclick = function() {
                insertAutotextInEditor(editor, item.conteudo);
                hideAutocomplete();
            };
            dd.appendChild(div);
        });
        const rect = editor.getBoundingClientRect();
        dd.style.top  = (rect.bottom + window.scrollY) + 'px';
        dd.style.left = (rect.left + window.scrollX) + 'px';
        dd.classList.remove('d-none');
    }

    function hideAutocomplete() {
        const dd = document.getElementById('autotext-dropdown');
        if (dd) dd.classList.add('d-none');
    }

    function insertAutotextInEditor(editor, content) {
        editor.focus();
        document.execCommand('insertHTML', false, content);
    }

    // ── Autotexto sidebar ────────────────────────────────────────────────
    window.insertAutotext = function(id, content) {
        if (!lastFocused) {
            showToast('Clique em uma seção do laudo antes de inserir o autotexto.', 'warning');
            return;
        }
        insertAutotextInEditor(lastFocused, content);
    };

    window.searchAutotext = function(q) {
        const items = document.querySelectorAll('.autotext-item');
        const ql = q.toLowerCase();
        items.forEach(function(item) {
            const text = item.textContent.toLowerCase();
            item.style.display = (!ql || text.includes(ql)) ? '' : 'none';
        });
    };

    // ── Histórico ────────────────────────────────────────────────────────
    window.loadHistory = function(reportId) {
        fetch('/reports/history?report_id=' + reportId)
        .then(r => r.json())
        .then(function(data) {
            if (!data.ok) return;
            const list = document.getElementById('history-list');
            list.innerHTML = '';
            data.versoes.forEach(function(v) {
                const div = document.createElement('div');
                div.className = 'rhistory-item';
                div.innerHTML =
                    '<div class="d-flex justify-content-between">' +
                    '<span class="badge bg-' + (v.acao === 'assinado' ? 'success' : 'secondary') + '">' + escHtml(v.acao) + '</span>' +
                    '<small class="text-muted">v' + v.versao + '</small></div>' +
                    '<div class="small mt-1">' + escHtml(v.usuario_nome || '—') + '</div>' +
                    '<div class="small text-muted">' + escHtml(v.data_fmt || '') + '</div>';
                list.appendChild(div);
            });
        });
    };

    // ── Toolbar ──────────────────────────────────────────────────────────
    window.execCmd = function(cmd, val) {
        document.execCommand(cmd, false, val || null);
        if (lastFocused) lastFocused.focus();
    };

    window.insertTable = function() {
        const html = '<table border="1" style="border-collapse:collapse;width:100%">' +
            '<tr><th style="padding:4px">Coluna 1</th><th style="padding:4px">Coluna 2</th></tr>' +
            '<tr><td style="padding:4px">&nbsp;</td><td style="padding:4px">&nbsp;</td></tr>' +
            '</table><br>';
        document.execCommand('insertHTML', false, html);
    };

    // ── Seções expansíveis ───────────────────────────────────────────────
    window.toggleSection = function(name) {
        const body = document.getElementById('body-' + name);
        const ico  = document.getElementById('ico-' + name);
        if (!body) return;
        const isHidden = body.style.display === 'none';
        body.style.display = isHidden ? '' : 'none';
        if (ico) {
            ico.className = isHidden ? 'bi bi-chevron-down' : 'bi bi-chevron-right';
        }
    };

    // ── Checklist ────────────────────────────────────────────────────────
    function initChecklist() {
        const chkCorpo = document.getElementById('chk-corpo');
        const chkAssin = document.getElementById('chk-assinatura');
        const progress = document.getElementById('checklist-progress');

        if (!chkCorpo) return;

        chkCorpo.checked = getEditorContent('corpo').replace(/<[^>]+>/g, '').trim().length > 5;
        if (chkAssin) chkAssin.checked = <?= in_array($situacao, ['assinado','liberado']) ? 'true' : 'false' ?>;

        const total = 2;
        const checked = [chkCorpo, chkAssin].filter(c => c && c.checked).length;
        if (progress) progress.style.width = Math.round((checked / total) * 100) + '%';
    }

    window.updateChecklist = function() { initChecklist(); };

    // ── Viewer sync (preparado para futuro) ─────────────────────────────
    window.syncViewer = function() {
        showToast('Sincronização com Viewer será implementada em versão futura.', 'info');
    };

    // ── Toast ────────────────────────────────────────────────────────────
    function showToast(msg, type) {
        type = type || 'info';
        const colors = { success: '#69f0ae', danger: '#ff5252', warning: '#ffca28', info: '#4fc3f7' };
        const toast = document.createElement('div');
        toast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;' +
            'background:var(--pacs-card-bg);border:1px solid ' + (colors[type] || colors.info) + ';' +
            'color:' + (colors[type] || colors.info) + ';padding:.6rem 1.2rem;border-radius:6px;' +
            'font-size:.85rem;box-shadow:0 4px 20px rgba(0,0,0,.5);animation:fadeIn .2s ease;';
        toast.textContent = msg;
        document.body.appendChild(toast);
        setTimeout(function() { toast.remove(); }, 3000);
    }

    // ── Escape HTML ──────────────────────────────────────────────────────
    function escHtml(str) {
        return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // ── Atalhos de teclado ───────────────────────────────────────────────
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            saveReport(true);
        }
        if (e.key === 'Escape') {
            hideAutocomplete();
        }
    });

    // ── Ciclo de vida do laudo ───────────────────────────────────────────────
    var _laudoLiberado  = false;
    var REPORT_ID_CICLO = <?= $reportId ?>;
    var READONLY_CICLO  = <?= ($somenteLeitura ?? false) ? 'true' : 'false' ?>;

    function atualizarStatusLaudo(situacao, useBeacon) {
        if (!REPORT_ID_CICLO || READONLY_CICLO) return;
        var payload = JSON.stringify({ report_id: REPORT_ID_CICLO, situacao: situacao });
        if (useBeacon && navigator.sendBeacon) {
            navigator.sendBeacon('/api/reports/status', new Blob([payload], { type: 'application/json' }));
        } else {
            fetch('/api/reports/status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: payload
            }).catch(function() {});
        }
    }

    // Ao abrir: marca em_laudo
    document.addEventListener('DOMContentLoaded', function() {
        atualizarStatusLaudo('em_laudo', false);
    });

    // Ao fechar sem liberar: marca rascunho
    window.addEventListener('beforeunload', function() {
        if (!_laudoLiberado) {
            atualizarStatusLaudo('rascunho', true);
        }
    });

    // Bind botão Liberar
    function bindBtnLiberar(btn) {
        if (!btn) return;
        btn.addEventListener('click', function() {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Liberando...';
            var payload = {
                report_id: REPORT_ID_CICLO,
                corpo_laudo: getEditorContent('corpo')
            };
            fetch('/api/reports/liberar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) {
                    _laudoLiberado = true;
                    showToast('Laudo liberado! Fechando...', 'success');
                    setTimeout(function() { window.close(); }, 1500);
                } else {
                    showToast('Erro: ' + (data.msg || 'Tente novamente.'), 'danger');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa fa-paper-plane"></i> Liberar';
                }
            })
            .catch(function() {
                showToast('Erro de conexão.', 'danger');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-paper-plane"></i> Liberar';
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        bindBtnLiberar(document.getElementById('btn-liberar'));
    });

    // Após assinar: substitui Assinar por Liberar
    document.addEventListener('report:assinado', function() {
        var btnSign = document.getElementById('btn-sign');
        if (btnSign) {
            var btnLib = document.createElement('button');
            btnLib.type      = 'button';
            btnLib.id        = 'btn-liberar';
            btnLib.className = 'pacs-btn btn-pacs-success';
            btnLib.title     = 'Liberar laudo e fechar';
            btnLib.innerHTML = '<i class="fa fa-paper-plane"></i> Liberar';
            btnSign.replaceWith(btnLib);
            bindBtnLiberar(btnLib);
        }
    });


})();
</script>
