<div class="ata-signed-downloads" {{ ($amendmentLinks ?? false) ? 'data-amendment-signed-downloads' : 'data-signed-downloads' }} hidden>
    <a class="text-link" data-signed-original>Baixar original</a>
    <span aria-hidden="true" data-signed-link-divider>|</span>
    <a class="text-link" data-signed-redacted>Baixar cópia tarjada</a>
    <button type="button" class="text-link" data-signed-create-redacted hidden>Criar cópia tarjada</button>
    <span aria-hidden="true">|</span>
    <button type="button" class="text-link" data-signed-remove>Remover PDFs</button>
</div>
