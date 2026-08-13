/**
 * VOXEL PACS — Reports / Measurements
 *
 * Consome snapshots já normalizados e autorizados pelo backend. O browser nunca
 * recebe nem reinsere o payload clínico bruto: envia somente IDs selecionados.
 */
window.VoxelReports = window.VoxelReports || {};

window.VoxelReports.measurements = (function () {
    let config = null;
    let measurements = [];
    let loading = false;
    let pollTimer = null;

    const card = () => document.getElementById('viewer-measurements-card');
    const list = () => document.getElementById('viewer-measurements-list');
    const status = () => document.getElementById('viewer-measurements-status');
    const insertButton = () => document.getElementById('btn-insert-measurements');
    const copyButton = () => document.getElementById('btn-copy-measurements');

    function setStatus(message) {
        const el = status();
        if (el) el.textContent = message;
    }

    function selectedIds() {
        return Array.from(document.querySelectorAll('.viewer-measurement-checkbox:checked'))
            .map((checkbox) => parseInt(checkbox.value, 10))
            .filter(Number.isInteger);
    }

    function selectedMeasurements() {
        const ids = new Set(selectedIds());
        return measurements.filter((measurement) => ids.has(parseInt(measurement.id, 10)));
    }

    function updateActions() {
        const hasSelection = selectedIds().length > 0;
        const readonly = !!config.readonly;
        if (copyButton()) copyButton().disabled = !hasSelection;
        if (insertButton()) insertButton().disabled = !hasSelection || readonly;
    }

    function clearList() {
        const el = list();
        if (el) el.replaceChildren();
        return el;
    }

    function render() {
        const el = clearList();
        if (!el) return;

        if (!measurements.length) {
            const empty = document.createElement('div');
            empty.className = 'viewer-measurements-empty';
            empty.textContent = 'Nenhuma medida sincronizada para este estudo.';
            el.appendChild(empty);
            updateActions();
            return;
        }

        measurements.forEach((measurement) => {
            const item = document.createElement('label');
            item.className = 'viewer-measurement-item';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'viewer-measurement-checkbox';
            checkbox.value = String(measurement.id);
            checkbox.disabled = !!config.readonly;
            checkbox.addEventListener('change', updateActions);

            const content = document.createElement('span');
            const value = document.createElement('span');
            value.className = 'viewer-measurement-value';
            value.textContent = measurement.display_value || 'Medida sem valor';

            const meta = document.createElement('span');
            meta.className = 'viewer-measurement-meta';
            const frame = measurement.frame_number ? ` · Frame ${measurement.frame_number}` : '';
            meta.textContent = `${measurement.tool_name || 'Measurement'}${frame}`;

            content.appendChild(value);
            content.appendChild(meta);

            if (measurement.label) {
                const label = document.createElement('span');
                label.className = 'viewer-measurement-label';
                label.textContent = measurement.label;
                content.appendChild(label);
            }

            item.appendChild(checkbox);
            item.appendChild(content);
            el.appendChild(item);
        });

        updateActions();
    }

    async function load() {
        if (!config || loading) return;
        loading = true;
        setStatus('Atualizando medidas do viewer…');

        try {
            const response = await fetch(`/api/reports/measurements?report_id=${encodeURIComponent(config.reportId)}`, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Não foi possível carregar as medidas.');
            }

            measurements = Array.isArray(data.measurements) ? data.measurements : [];
            render();
            setStatus(measurements.length
                ? `${measurements.length} medida(s) disponível(is) para este estudo.`
                : 'Nenhuma medida sincronizada para este estudo.');
        } catch (error) {
            measurements = [];
            render();
            setStatus('Não foi possível atualizar as medidas. Tente novamente.');
        } finally {
            loading = false;
        }
    }

    function measurementText(measurement) {
        let text = `${measurement.display_value || ''} — ${measurement.tool_name || 'Measurement'}`;
        if (measurement.label) text += ` (${measurement.label})`;
        return text.trim();
    }

    async function copy() {
        const selected = selectedMeasurements();
        if (!selected.length) return;

        const text = selected.map(measurementText).join('\n');
        try {
            if (!navigator.clipboard || !window.isSecureContext) {
                throw new Error('Clipboard API indisponível');
            }
            await navigator.clipboard.writeText(text);
            setStatus(`${selected.length} medida(s) copiada(s).`);
        } catch (error) {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.setAttribute('readonly', '');
            textArea.style.position = 'fixed';
            textArea.style.opacity = '0';
            document.body.appendChild(textArea);
            textArea.select();
            const copied = document.execCommand('copy');
            textArea.remove();
            setStatus(copied ? `${selected.length} medida(s) copiada(s).` : 'Não foi possível copiar as medidas.');
        }
    }

    async function insert() {
        const ids = selectedIds();
        if (!ids.length || config.readonly) return;

        const target = document.getElementById('measurement-target-section');
        const section = target ? target.value : 'achados';
        const button = insertButton();
        if (button) button.disabled = true;
        setStatus('Inserindo medidas no laudo…');

        try {
            const response = await fetch('/api/reports/measurements/insert', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': config.csrf,
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    report_id: config.reportId,
                    measurement_ids: ids,
                    secao_destino: section,
                }),
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Não foi possível inserir as medidas.');
            }

            if (data.secoes && window.VoxelReports.editor) {
                window.VoxelReports.editor.loadSecoes(data.secoes);
                if (window.VoxelReports.autosave && window.VoxelReports.autosave.markSaved) {
                    window.VoxelReports.autosave.markSaved();
                }
            }
            setStatus(data.inserted
                ? `${data.inserted} medida(s) inserida(s) na seção selecionada.`
                : (data.message || 'As medidas selecionadas já estavam inseridas.'));
            await load();
        } catch (error) {
            setStatus('Não foi possível inserir as medidas. Nenhuma alteração foi aplicada.');
        } finally {
            updateActions();
        }
    }

    function init(cfg) {
        config = cfg;
        if (!card() || !config.reportId) return;

        const refresh = document.getElementById('btn-refresh-measurements');
        if (refresh) refresh.addEventListener('click', load);
        if (copyButton()) copyButton().addEventListener('click', copy);
        if (insertButton()) insertButton().addEventListener('click', insert);

        load();
        pollTimer = window.setInterval(load, 15000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) load();
        });
    }

    function destroy() {
        if (pollTimer) window.clearInterval(pollTimer);
        pollTimer = null;
    }

    return { init, load, destroy };
})();
