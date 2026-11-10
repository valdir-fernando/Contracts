<button type="button" popovertarget="ata-{{ $name }}-popover" x-ref="trigger" aria-label="{{ $label }}" title="{{ $label }}" @class(['report-process-trigger', 'active' => collect(array_keys($fields))->contains(fn($key) => $filters['report_'.$key] !== '')])><x-nav-icon name="filter"/></button>
<div id="ata-{{ $name }}-popover" class="report-process-popover" popover @toggle="if ($event.newState === 'open') { const rect = $refs.trigger.getBoundingClientRect(); $el.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 268)) + 'px'; $el.style.top = Math.max(8, Math.min(rect.bottom + 6, window.innerHeight - $el.offsetHeight - 8)) + 'px'; }">
    <div class="report-process-popover-heading"><span>{{ $label }}</span><button type="button" popovertarget="ata-{{ $name }}-popover" popovertargetaction="hide" aria-label="Fechar filtro">×</button></div>
    @foreach($fields as $key => $caption)
        <label class="report-supplier-filter-field" for="ata-filter-{{ $key }}">{{ $caption }}
            <input id="ata-filter-{{ $key }}" class="report-process-filter" type="search" name="report_{{ $key }}" form="ata-report-filters" value="{{ $filters['report_'.$key] }}" maxlength="255" @if($loop->first) autofocus @endif>
        </label>
    @endforeach
    <div class="report-process-popover-actions">
        <button type="button" class="secondary-button compact" @click="$el.closest('[popover]').querySelectorAll('input').forEach(input => input.value = ''); document.getElementById('ata-report-filters').requestSubmit()">Limpar</button>
        <button type="submit" form="ata-report-filters" class="primary-button compact">Aplicar</button>
    </div>
</div>
