(() => {
  'use strict';

  const blockedControls = new Set([
    'MeasurementTools', 'MoreTools', 'Layout', 'Capture', 'RotateRight',
    'FlipHorizontal', 'Invert', 'CalibrationLine', 'Crosshairs',
    'ReferenceLines', 'Cine', 'Settings', 'panelSegmentation-btn',
    'trackedMeasurements-panel', 'Length', 'Bidirectional', 'EllipticalROI',
    'CircleROI', 'RectangleROI', 'Probe', 'ArrowAnnotate', 'Segmentation',
  ]);

  const allowedControls = new Set([
    'Zoom', 'Pan', 'WindowLevelGroup', 'WindowLevelGroup-split-button-primary',
    'WindowLevelGroup-split-button-secondary', 'Reset', 'StackScroll',
    'studyBrowser-panel',
  ]);

  const hideAdvancedUi = () => {
    document.querySelectorAll('[data-cy]').forEach((element) => {
      const id = element.getAttribute('data-cy') || '';
      if (blockedControls.has(id)) {
        element.style.setProperty('display', 'none', 'important');
      }
      if (allowedControls.has(id)) {
        element.style.removeProperty('display');
      }
    });

    document.querySelectorAll('a, button, [role="button"]').forEach((element) => {
      const text = (element.textContent || '').replace(/\s+/g, ' ').trim();
      const label = `${element.getAttribute('aria-label') || ''} ${element.getAttribute('title') || ''} ${text}`.toLowerCase();
      if (/open health imaging foundation|ohif|upload|download|export|settings|measurement|segmentation/.test(label)) {
        element.style.setProperty('display', 'none', 'important');
      }
    });
  };

  const mountBrand = () => {
    if (!document.getElementById('voxel-patient-brand')) {
      const brand = document.createElement('div');
      brand.id = 'voxel-patient-brand';
      brand.setAttribute('aria-label', 'VOXEL Imagens do Paciente');
      brand.innerHTML = '<span>VOXEL IMAGENS<small>VISUALIZAÇÃO DO PACIENTE</small></span>';
      document.body.appendChild(brand);
    }
    if (!document.getElementById('voxel-patient-disclaimer')) {
      const notice = document.createElement('div');
      notice.id = 'voxel-patient-disclaimer';
      notice.textContent = 'Visualização de imagens anonimizadas para consulta do paciente. Não substitui a interpretação do laudo médico.';
      document.body.appendChild(notice);
    }
    document.title = 'VOXEL Imagens do Paciente';
  };

  const refresh = () => {
    mountBrand();
    hideAdvancedUi();
  };

  document.addEventListener('DOMContentLoaded', refresh, { once: true });
  new MutationObserver(refresh).observe(document.documentElement, { childList: true, subtree: true });
})();
