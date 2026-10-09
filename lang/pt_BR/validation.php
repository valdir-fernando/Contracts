<?php

return [
    'required' => 'O campo :attribute é obrigatório.',
    'string' => 'O campo :attribute deve ser um texto.',
    'email' => 'Informe um e-mail válido.',
    'boolean' => 'Selecione uma situação válida.',
    'integer' => 'O campo :attribute deve ser um número inteiro.',
    'array' => 'Selecione vínculos válidos.',
    'exists' => 'O vínculo selecionado não existe.',
    'in' => 'O valor selecionado em :attribute é inválido.',
    'unique' => 'O valor informado em :attribute já está cadastrado.',
    'distinct' => 'Não repita os vínculos selecionados.',
    'prohibited' => 'O campo :attribute não pode ser alterado nesta ação.',
    'confirmed' => 'A confirmação de :attribute não confere.',
    'min' => ['string' => 'O campo :attribute deve ter pelo menos :min caracteres.'],
    'max' => ['string' => 'O campo :attribute deve ter no máximo :max caracteres.'],
    'password' => ['letters' => 'A senha deve conter letras.', 'numbers' => 'A senha deve conter números.'],
    'attributes' => ['name' => 'nome', 'username' => 'usuário', 'user_type' => 'perfil', 'password' => 'senha', 'reason' => 'motivo', 'confirmation' => 'confirmação', 'scope_ids' => 'fundos e secretarias', 'is_active' => 'situação'],
];
