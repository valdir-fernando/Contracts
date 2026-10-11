# Estado da entrega e pendências externas — 11/10/2026

## Funções internas entregues

| Área | Entrega |
|---|---|
| MVP | Autenticação, usuários/escopos, 80 campos contratuais, CRUD auditado, histórico/conciliação, relatórios, conta pessoal e operação |
| P1 | Documentos privados, calendário/alertas internos, pesquisa avançada, fornecedores assistidos, requisitos por tipo, 44 campos de aditivos, dotação/fichas/empenhos |
| P2 interno | Relatórios com colunas e personalizações, gráficos detalhados, API de consulta autenticada e importação periódica opcional |
| Conferência | Registro explícito de término atualizado e valor acumulado, conclusão/reabertura da revisão e trilha de auditoria |
| Visual | Navegação, login, painel, campos, tabelas e adaptação móvel refinados |

Os cadastros financeiros são documentais. Cálculos automáticos patrimoniais, saldo de dotação, liquidação/pagamento e inferência de efeitos jurídicos não foram definidos na especificação. A conferência registra valores e datas informados pela administração. Os dados legados brutos continuam preservados e as ambiguidades exigem conciliação.

## Pendências externas mantidas por decisão do usuário

- Assinatura eletrônica: escolher provedor, modelo de identidade e regras de assinatura.
- Integrações públicas/entre sistemas: definir serviços, credenciais, contrato de dados e finalidade.
- Publicação externa: definir hospedagem, domínio/TLS, segredos e destino externo de backup.
- Homologação da área: disponibilizar snapshot real, conferir amostras e relações financeiras, aprovar regras pendentes, matriz de acesso e retenção documental.

Não houve publicação em infraestrutura externa. A aplicação local permanece em `http://localhost:8000`. Os registros `SMOKE-` são fictícios, criados pelo ensaio de navegador.

## Instalar esta evolução

Execute as migrations e compile os assets; reinicie workers para carregar as mudanças de relatórios. Consulte [MVP e operação](11_MVP_E_OPERACAO.md) para instalação completa, backup e publicação de imagens. As novas migrations adicionam aditivos, registros financeiros e personalizações sem apagar os dados existentes.

```bash
docker compose exec app php artisan migrate --force
docker compose run --rm node npm run build
docker compose exec app php artisan queue:restart
```

Manuais: [aditivos](14_ADITIVOS.md), [dotação/fichas/empenhos](15_DOTACAO_FICHAS_E_EMPENHOS.md), [funções internas e visual](16_FUNCOES_INTERNAS_E_VISUAL.md). Evidências de validação estão no [registro de validação](12_REGISTRO_DE_VALIDACAO.md).
