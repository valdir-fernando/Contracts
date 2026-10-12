<div class="report-generation-list" data-report-cards aria-label="Relatórios solicitados"></div>
<template data-report-card-template>
    <article class="surface report-generation-card" data-report-card aria-busy="true">
        <span class="report-spinner" data-report-spinner aria-hidden="true"></span>
        <div class="report-generation-body">
            <h3 data-report-title></h3>
            <p role="status" aria-live="polite" data-report-message>Gerando relatório… Você pode continuar nesta página.</p>
        </div>
        <a class="primary-button compact" data-report-download hidden>Baixar relatório</a>
    </article>
</template>
