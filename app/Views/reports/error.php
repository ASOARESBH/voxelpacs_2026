<?php
$mensagem = htmlspecialchars($mensagem ?? 'Erro desconhecido.', ENT_QUOTES);
?>
<div class="container-fluid py-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card bg-dark border-danger text-center p-4">
                <i class="bi bi-exclamation-triangle-fill text-danger" style="font-size:3rem"></i>
                <h4 class="mt-3 text-danger">Erro ao abrir laudo</h4>
                <p class="text-muted mt-2"><?= $mensagem ?></p>
                <a href="/estudos" class="btn btn-outline-secondary mt-3">
                    <i class="bi bi-arrow-left me-1"></i> Voltar para Worklist
                </a>
            </div>
        </div>
    </div>
</div>
