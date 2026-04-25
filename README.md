# Flow Observatory for AS-Stats

Baseado em:

- [Como obter graficos de trafego por AS utilizando AS-Stats](https://blog.remontti.com.br/5129)
- [remontti/AS-Stats](https://github.com/remontti/AS-Stats)

## Visao geral

Este projeto empacota uma instalacao do AS-Stats para Ubuntu com identidade visual propria `flow`, URL `/flow` e uma trilha paralela de consulta por IP.

Arquivos principais:

- [install_asstats_ubuntu.sh](C:/Users/Rocha/Documents/Codex/2026-04-24-voc-consegue-me-ajudar-a-desenvolver/flow/install_asstats_ubuntu.sh)
- [apply_flow_full_customization.sh](C:/Users/Rocha/Documents/Codex/2026-04-24-voc-consegue-me-ajudar-a-desenvolver/flow/apply_flow_full_customization.sh)
- [flow_patch_collector.py](C:/Users/Rocha/Documents/Codex/2026-04-24-voc-consegue-me-ajudar-a-desenvolver/flow/flow_patch_collector.py)
- [flow_webui](C:/Users/Rocha/Documents/Codex/2026-04-24-voc-consegue-me-ajudar-a-desenvolver/flow/flow_webui)

## O que o instalador faz

O instalador:

- detecta a versao do Ubuntu
- instala dependencias de sistema, Perl, PHP, Apache, SNMP e RRD
- clona o `AS-Stats` em `/data/asstats`
- cria `rrd/`, `asstats/`, `conf/` e o diretorio web
- descobre interfaces via SNMP e gera `knownlinks`
- corrige o `knownlinks` para o IP real de origem do flow quando necessario
- aplica a UI `flow`
- publica a interface em `/flow`
- cria `asstatsd.service` e `asstats-extract.timer`
- corrige o `asstatd.pl` para gravar uma base paralela de consulta por IP
- instala `/usr/local/bin/asstats-add-router.sh` para anexar novos roteadores depois

## Instalacao rapida

Baixe o projeto no servidor:

```bash
cd /opt
sudo git clone https://github.com/cadu18bv/flow.git
cd /opt/flow
sudo chmod +x install_asstats_ubuntu.sh apply_flow_full_customization.sh
```

Rode o instalador com menu:

```bash
cd /opt/flow
sudo ./install_asstats_ubuntu.sh
```

Ou rode direto no modo desejado:

Instalar ou atualizar o pacote completo:

```bash
cd /opt/flow
sudo ASSTATS_ACTION=install ./install_asstats_ubuntu.sh
```

Aplicar theme e corretivas em instalacao existente:

```bash
cd /opt/flow
sudo ASSTATS_ACTION=theme ./install_asstats_ubuntu.sh
```

Adicionar mais um roteador/exportador no flow:

```bash
cd /opt/flow
sudo ASSTATS_ACTION=add-router ./install_asstats_ubuntu.sh
```

Ao iniciar, o script agora pergunta qual operacao voce quer executar:

- instalar ou atualizar o pacote completo
- aplicar tema e corretivas `flow` em uma instalacao existente
- adicionar mais um roteador/exportador no flow

Se preferir rodar sem menu, voce pode definir a acao antes:

```bash
sudo ASSTATS_ACTION=install ./install_asstats_ubuntu.sh
sudo ASSTATS_ACTION=theme ./install_asstats_ubuntu.sh
sudo ASSTATS_ACTION=add-router ./install_asstats_ubuntu.sh
```

## Atualizar o repositorio

Se o projeto ja estiver baixado no servidor:

```bash
cd /opt/flow
sudo git pull
sudo chmod +x install_asstats_ubuntu.sh apply_flow_full_customization.sh
```

Variaveis opcionais:

```bash
sudo ASSTATS_PORT_NETFLOW=9000 \
     ASSTATS_PORT_SFLOW=6343 \
     ASSTATS_MY_ASN=1234 \
     ASSTATS_SAMPLING_RATE=128 \
     ASSTATS_WEB_ALIAS=flow \
     ASSTATS_FLOW_RETENTION_DAYS=14 \
     ./install_asstats_ubuntu.sh
```

Padroes:

- `ASSTATS_PORT_NETFLOW=9000`
- `ASSTATS_PORT_SFLOW=6343`
- `ASSTATS_SAMPLING_RATE=128`
- `ASSTATS_WEB_ALIAS=flow`
- `ASSTATS_FLOW_RETENTION_DAYS=14`

## knownlinks

Formato esperado:

```text
IP_DO_EXPORTADOR<TAB>IFINDEX<TAB>TAG<TAB>DESCRICAO<TAB>CORHEX<TAB>SAMPLING
```

Exemplo:

```text
172.17.1.1	2984	if2984	PTP LINK IP PIX FIBRA	1F78B4	128
```

Arquivo final:

```text
/data/asstats/conf/knownlinks
```

## Fluxo de coleta

O pacote passa a ter duas trilhas:

`1.` Trilha original do AS-Stats

- `asstatsd.service` recebe NetFlow/sFlow
- grava series em `/data/asstats/rrd`
- `asstats-extract.service` roda `rrd-extractstats.pl`
- atualiza `/data/asstats/asstats/asstats_day.txt`

`2.` Trilha paralela `flow` para lookup por IP

- o coletor corrigido continua classificando ASN
- em paralelo agrega por minuto e grava `src_ip`, `dst_ip`, `src_asn`, `dst_asn`, `link_tag`, `direction`, `ip_version`, `bytes` e `samples`
- atualiza `/data/asstats/asstats/flow_events.db`

Arquivos SQLite:

```text
/data/asstats/asstats/asstats_day.txt
/data/asstats/asstats/flow_events.db
```

Observacao:

- a busca por IP vale para dados coletados depois da corretiva do coletor
- o historico antigo do AS-Stats nao tem granularidade por IP

## Acesso web

Depois da instalacao:

```text
http://IP_DO_SERVIDOR/flow/
```

Rotas principais:

- `/flow/` radar ASN
- `/flow/history.php` ASN Explorer
- `/flow/ipsearch.php` IP Lens
- `/flow/asset.php` AS-SET Studio
- `/flow/ix.php` IX Analytics
- `/flow/linkusage.php` Link Flow

## Aplicar em instalacao existente

Para aplicar a UI `flow`, a nova paleta dos graficos e a corretiva do coletor em uma instalacao ja existente:

```bash
chmod +x apply_flow_full_customization.sh
sudo ./apply_flow_full_customization.sh /data/asstats flow
```

Esse script:

- substitui as paginas web pela UI `flow`
- adiciona `ipsearch.php`
- ajusta `gengraph.php` e `linkgraph.php` com paleta refinada
- corrige o coletor com [flow_patch_collector.py](C:/Users/Rocha/Documents/Codex/2026-04-24-voc-consegue-me-ajudar-a-desenvolver/flow/flow_patch_collector.py)
- reconfigura o wrapper `asstatsd-wrapper.sh` para usar `flow_events.db`
- instala `/usr/local/bin/asstats-add-router.sh`
- recarrega Apache e reinicia o coletor

## Adicionar mais roteadores

Depois da instalacao, voce pode anexar novos exportadores sem reinstalar tudo:

```bash
/usr/local/bin/asstats-add-router.sh /data/asstats
```

Esse utilitario:

- pergunta o IP/hostname do novo roteador
- consulta SNMP
- mostra as interfaces descobertas
- anexa as novas linhas ao `knownlinks`
- reinicia o coletor
- dispara o extrator

## Validacoes uteis

Status:

```bash
systemctl status asstatsd.service
systemctl status asstats-extract.timer
```

Logs:

```bash
journalctl -u asstatsd.service -n 100 --no-pager
journalctl -u asstats-extract.service -n 100 --no-pager
```

Forcar extrator:

```bash
systemctl start asstats-extract.service
```

Conferir o banco principal:

```bash
sqlite3 /data/asstats/asstats/asstats_day.txt '.tables'
sqlite3 /data/asstats/asstats/asstats_day.txt 'select count(*) from stats;'
```

Conferir a base paralela de IP:

```bash
sqlite3 /data/asstats/asstats/flow_events.db '.tables'
sqlite3 /data/asstats/asstats/flow_events.db 'select count(*) from flow_events;'
```

Conferir uma linha do `knownlinks`:

```bash
grep -n 'CDN' /data/asstats/conf/knownlinks
```
