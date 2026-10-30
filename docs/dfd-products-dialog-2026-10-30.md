# Janela de produtos da DFD — 30/10/2026

O botão Produtos de uma DFD salva abre um dialog separado, com o tamanho e o fechamento do cadastro de produtos de contratos. Nesta etapa, a janela contém apenas título e botão X; seu corpo fica vazio para construção incremental dos controles.

Informações gerais permanece no painel principal. Pesquisa, catálogo, formulário e lista de produtos foram retirados da apresentação. Dados salvos, rotas, autorização e auditoria existentes são preservados. URLs antigas com tab=products também mostram o painel geral com a janela vazia disponível pelo botão Produtos.

Validação executada:
- docker compose exec -T app php artisan test --filter=DfdPanelTest: 10 testes, 143 asserções aprovadas, SQLite em memória.
- docker compose exec -T app composer check:architecture: 3 testes, 4 asserções aprovadas.
- git diff --check: aprovado.

Sem validação visual autenticada em navegador. Alteração somente em Blade e testes; sem alteração dos assets.

## Evolução da lista e pesquisa

A janela apresenta Fundo no cabeçalho, classe e número da DFD, divisória vertical e catálogo com seleção múltipla e rolagem. Ao perder o foco, o campo pesquisa trechos do nome, ignorando caixa e acentos. Produtos selecionados continuam visíveis junto aos resultados. Limpar o campo restaura imediatamente todos os registros sem desmarcar itens. A seleção dura enquanto a página permanece aberta.

O catálogo agora retorna uma coleção completa dos produtos ativos da classe autorizada, com projeção explícita de ID, código, descrição e unidade; sem paginação. O filtro é local e não altera o banco.

Validação: teste JavaScript de filtro, seleção, limpeza e resultado vazio aprovado; DfdPanelTest com 10 testes/144 asserções aprovado em SQLite; Architecture com 3 testes/4 asserções aprovado; build aprovado; git diff --check aprovado. Pint detectou formatação no teste PHP e foi aplicado. Sem ensaio visual autenticado.

## Lançamento individual

O ícone dentro de QTD envia o produto selecionado, quantidade brasileira e complemento à rota POST existente, com CSRF e versão. A gravação mantém autorização, locks, validação contextual e auditoria transacionais de DfdRegistration. A resposta redirecionada fornece a projeção HTML reautorizada; o cliente atualiza apenas a lista vinculada e as versões dos formulários. Seleções e complementos locais são preservados. Erros são apresentados sem substituir a lista; envios simultâneos são bloqueados durante a requisição.

Validação executada: DfdPanelTest, 11 testes/155 asserções aprovadas em SQLite, incluindo quantidade 1.654,000, complemento, auditoria, conflito de versão e rejeição de zero; 2 testes JavaScript aprovados; npm run build aprovado; Pint do teste aprovado; git diff --check aprovado. Sem ensaio visual autenticado.
