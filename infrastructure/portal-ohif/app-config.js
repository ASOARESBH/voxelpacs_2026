/**
 * Viewer dedicado ao Portal de Resultados.
 * Sem upload, exportação, worklist, configuração dinâmica ou integração ao
 * Viewer interno. A autorização é feita por cookie HttpOnly no gateway same-origin.
 */
window.config = {
  routerBasename: '/imagens/viewer',
  extensions: [],
  modes: [],
  showStudyList: false,
  showPatientInfo: 'disabled',
  disableEditing: true,
  allowMultiSelectExport: false,
  maxNumberOfWebWorkers: 2,
  showLoadingIndicator: true,
  showWarningMessageForCrossOrigin: false,
  showCPUFallbackMessage: false,
  // A orientação de uso do paciente é exibida pela camada VOXEL do Portal.
  investigationalUseDialog: { option: 'never' },
  dangerouslyUseDynamicConfig: { enabled: false },
  defaultDataSourceName: 'voxelPortalDicomWeb',
  dataSources: [
    {
      namespace: '@ohif/extension-default.dataSourcesModule.dicomweb',
      sourceName: 'voxelPortalDicomWeb',
      configuration: {
        friendlyName: 'VOXEL Imagens do Paciente',
        name: 'VOXEL_PORTAL',
        wadoUriRoot: '/imagens/dicom-web',
        qidoRoot: '/imagens/dicom-web',
        wadoRoot: '/imagens/dicom-web',
        qidoSupportsIncludeField: false,
        supportsReject: false,
        supportsFuzzyMatching: false,
        supportsWildcard: false,
        dicomUploadEnabled: false,
        imageRendering: 'wadors',
        thumbnailRendering: 'wadors',
        enableStudyLazyLoad: false,
        omitQuotationForMultipartRequest: true,
        acceptHeader: ['multipart/related; type=application/octet-stream; transfer-syntax=*'],
        maxNumRequests: { interaction: 2, thumbnail: 2, prefetch: 0 },
      },
    },
  ],
};
