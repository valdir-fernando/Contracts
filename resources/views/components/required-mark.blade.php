@props(['when' => true, 'explain' => false])
@if($when)<span class="required-mark" aria-hidden="true">*</span>@if($explain)<span class="required-field-label"> Campo Obrigatório</span>@endif @endif
