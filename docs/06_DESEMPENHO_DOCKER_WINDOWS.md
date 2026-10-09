# Diagnóstico de desempenho — 09/10/2026

O projeto usa um bind mount de `C:\Projects\Contracts` para containers Linux. Antes da mudança, esse mount incluía `vendor`, `storage` e `bootstrap/cache`.

Medições HTTP locais, três requisições sequenciais por caminho, sem seguir redirects:

| Caminho | Tempo total antes | Tempo total depois |
| --- | --- | --- |
| `/robots.txt` | 6–8 ms | 7 ms |
| CSS compilado | 9–10 ms | 9–11 ms |
| `/up` | 1.791–4.509 ms | 30–44 ms após aquecimento; 306 ms na primeira requisição |
| `/login` | 2.055–5.001 ms | 44–78 ms |
| `/dashboard` sem autenticação (302) | 1.868–2.010 ms | 30–34 ms |

A conexão TCP levou aproximadamente 1 ms. Não havia pressão evidente de CPU ou RAM nos containers no momento da coleta.

O diagnóstico de arquivos comparou 195 leituras de arquivos PHP do framework: 2.575,8 ms no mount Windows contra 4,8 ms em cópias no filesystem Linux temporário. Inicializar o Laravel pela CLI levou 4.787,1 ms, e a primeira conexão com consulta `SELECT 1` levou 283,6 ms. O endpoint `/up`, que não consulta o banco explicitamente, também estava lento. Essas medições identificam o acesso aos arquivos como o principal gargalo observado, sem excluir variações do ambiente.

A correção move as dependências PHP, storage e cache do bootstrap para volumes nomeados Linux. O código permanece editável no Windows. A configuração do PHP mantém validação de mudanças, com intervalo de um segundo, e aumenta o limite de arquivos do OPcache e o cache de caminhos. Os dados existentes de storage/cache foram copiados; o volume MySQL foi preservado.

Após recriar apenas o serviço PHP e reiniciar o Nginx, as 195 leituras de `vendor` levaram 4,2 ms, a inicialização CLI do Laravel levou 183,9 ms e a conexão/consulta ao banco levou 11,6 ms. A suíte de autenticação passou com 8 testes e 62 asserções. As medições HTTP são do servidor local, sem renderização do navegador; o dashboard medido corresponde ao redirecionamento para visitantes sem sessão.

O teste pode ser repetido com `docker compose exec -T app php scripts/diagnose-performance.php`. Esse script apenas mede leituras e uma consulta `SELECT 1`; remove as cópias temporárias que cria. O campo `mounted_vendor` mede o volume atualmente configurado.

A [documentação do Docker](https://docs.docker.com/desktop/features/wsl/best-practices/) recomenda armazenar arquivos montados no filesystem Linux para melhorar o desempenho. Para eliminar também o custo do código da aplicação no bind mount, uma evolução possível é manter o projeto inteiro dentro do WSL. Não foi necessário mover o workspace nesta correção.

## Segunda análise: Vite e conexão por localhost

Na retomada, os volumes Linux continuavam funcionando. O Vite, porém, estava ativo com polling padrão no workspace Windows e consumia 49,27% de CPU sem interação. A inicialização CLI do Laravel levou 517 ms e a consulta de conexão ao banco, 31,6 ms.

Também foi medido um atraso na conexão por `localhost`: 203–213 ms para conectar, contra 1–3 ms por `127.0.0.1`. As portas do Compose são publicadas apenas em IPv4; a comparação mostra o custo da tentativa IPv6 antes da conexão IPv4 neste ambiente. Até `/robots.txt` levou 223 ms por localhost e 15–26 ms pelo endereço IPv4.

Correções aplicadas:

- Inicialização padrão com build estático, Vite desligado e remoção de `public/hot` depois de um build bem-sucedido. O serviço `node` passou ao perfil `assets`, evitando sua inicialização por `docker compose up -d`.
- Opção `scripts/start.ps1 -HotReload` para desenvolvimento com Vite. O polling foi ajustado para um segundo e exclui dependências PHP, storage, caches e dump legado.
- Endereço local de acesso e `APP_URL` alterados para `http://127.0.0.1:8000`, inclusive no exemplo de configuração. O HMR usa o mesmo endereço.
- CSS carregado apenas pela entrada CSS do layout, removendo sua importação redundante pela entrada JavaScript.
- Cache de um ano para assets compilados com hash no nome, servido pelo Nginx. Rotas e páginas autenticadas não recebem essa regra.

Medições HTTP após parar o Vite, compilar os assets e usar IPv4:

| Caminho | Antes, localhost + Vite | Depois, IPv4 + build |
| --- | --- | --- |
| `/login` | 307–372 ms | 47–49 ms após aquecimento; 88 ms na primeira amostra |
| `/up` | 242 ms na amostra inicial | 35–36 ms |
| `/dashboard`, visitante | 275–301 ms | 37–41 ms |
| `/users`, visitante | 273–307 ms | 41–53 ms |

O bootstrap CLI caiu para 220 ms e a conexão/consulta ao banco para 13,1 ms. Essas medições combinam a redução de carga e a mudança de endereço; a comparação separada de conexão acima identifica o componente de rede. Os tempos de dashboard e usuários são redirecionamentos sem autenticação, não medições das telas autenticadas nem de renderização no navegador.

Depois de alterar CSS/JavaScript no modo padrão, execute `docker compose run --rm --no-deps node npm run build`. Para retornar do modo HotReload ao build, execute o script de inicialização sem `-HotReload`.
