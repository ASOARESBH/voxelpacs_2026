<?php
// Endpoint legado de diagnóstico removido da superfície pública; não carrega bootstrap.
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
exit;
