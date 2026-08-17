<?php
/**
 * Busca inline de Máscaras de Laudo.
 * O conteúdo é preenchido por public/assets/js/reports/reports-templates.js.
 */
?>
<section class="pacs-card reports-card reports-mascara-search-card" id="mascara-search-card" aria-label="Buscar máscara de laudo">
    <div class="pacs-card-body reports-card-body reports-mascara-search-body">
        <label class="visually-hidden" for="mascara-search-input">Buscar máscara de laudo</label>
        <div class="reports-mascara-search-control">
            <i class="fa fa-magnifying-glass reports-mascara-search-icon" aria-hidden="true"></i>
            <input type="search"
                   id="mascara-search-input"
                   class="reports-mascara-search-input"
                   placeholder="Digite para encontrar sua máscara"
                   autocomplete="off"
                   role="combobox"
                   aria-autocomplete="list"
                   aria-expanded="false"
                   aria-controls="mascara-search-dropdown"
                   aria-activedescendant="">
            <button type="button"
                    class="reports-mascara-search-clear"
                    id="mascara-search-clear"
                    aria-label="Limpar busca de máscara"
                    title="Limpar busca"
                    hidden>
                <i class="fa fa-xmark" aria-hidden="true"></i>
            </button>
        </div>
        <div id="mascara-search-dropdown"
             class="reports-mascara-search-dropdown"
             role="listbox"
             aria-label="Máscaras disponíveis"
             hidden></div>
    </div>
</section>
