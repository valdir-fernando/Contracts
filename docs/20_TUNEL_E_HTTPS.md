# Túnel temporário e HTTPS

Com a aplicação Docker disponível em `http://127.0.0.1:8000`, um Quick Tunnel pode ser iniciado com:

```bash
cloudflared tunnel --url http://127.0.0.1:8000 --no-autoupdate
```

O endereço `https://….trycloudflare.com` aparece na saída do comando. Ele é temporário e depende do processo em execução. Consulte a [documentação do Cloudflare](https://developers.cloudflare.com/tunnel/get-started/quick-tunnels/).

## Reconhecimento do HTTPS

Configure `TRUSTED_PROXIES` no `.env` com os IPs dos proxies vistos pela aplicação, separados por vírgula. A lista fica vazia por padrão. Na instalação Docker local, o Nginx encaminha ao PHP o endereço do cliente que recebeu; para o túnel iniciado no host, esse endereço é o gateway da rede Docker, que pode ser conferido no log de acesso do Nginx.

Depois da configuração:

```bash
docker compose exec -T app php artisan config:clear
```

O middleware aceita `X-Forwarded-Proto` apenas dos IPs configurados. Isso mantém assets, formulários e redirecionamentos no HTTPS público, preservando o acesso HTTP local. O hostname não é substituído por `X-Forwarded-Host`.

Verificação: o teste automatizado cobre proxies autorizados, IPs não autorizados, lista vazia e preservação do hostname. No navegador, o endereço público respondeu com login, CSS, JavaScript e Livewire em HTTPS; o painel protegido redirecionou para o login. Os testes de autenticação também passaram.

Logs, identificador do processo, endereço temporário e configuração local ficam fora do repositório.

## Conferência antes do push

A suíte completa passou com 99 testes e 1.271 asserções. Pint aprovado nos 122 arquivos PHP. Assets Vite e templates Blade compilados; diferenças sem erros. Os ensaios de navegador dos gráficos, contadores, localização e login estão registrados nos documentos de refinamento visual e localização.
