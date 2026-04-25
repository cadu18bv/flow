# Flow Observatory

Plataforma de observabilidade de trafego com interface web `flow`, coleta por ASN, consulta por IP origem/destino e operacao simplificada para ambientes Ubuntu.

## O que este projeto entrega

- instalacao automatizada do ambiente em Ubuntu
- publicacao da interface em `/flow`
- tema visual proprio da plataforma
- coleta principal para visao por ASN
- base paralela para busca por IP origem/destino
- utilitario para anexar novos roteadores/exportadores depois da instalacao

## Estrutura principal

Arquivos mais importantes do projeto:

- [install_asstats_ubuntu.sh](C:/Users/Rocha/Documents/Codex/2026-04-24-voc-consegue-me-ajudar-a-desenvolver/flow/install_asstats_ubuntu.sh)
- [apply_flow_full_customization.sh](C:/Users/Rocha/Documents/Codex/2026-04-24-voc-consegue-me-ajudar-a-desenvolver/flow/apply_flow_full_customization.sh)
- [flow_patch_collector.py](C:/Users/Rocha/Documents/Codex/2026-04-24-voc-consegue-me-ajudar-a-desenvolver/flow/flow_patch_collector.py)
- [flow_webui](C:/Users/Rocha/Documents/Codex/2026-04-24-voc-consegue-me-ajudar-a-desenvolver/flow/flow_webui)

## Instalacao rapida

Baixe o projeto no servidor:

```bash
cd /opt
sudo git clone https://github.com/cadu18bv/flow.git
cd /opt/flow
sudo chmod +x install_asstats_ubuntu.sh apply_flow_full_customization.sh
```

Rode com menu interativo:

```bash
cd /opt/flow
sudo ./install_asstats_ubuntu.sh
```

## Modos de execucao

O instalador oferece 3 operacoes:

- instalar ou atualizar o pacote completo
- aplicar tema e corretivas em uma instalacao existente
- adicionar mais um roteador/exportador no flow

Durante `install` e `theme`, o script tambem pergunta qual timezone deve ser usado no ambiente. Esse ajuste e aplicado no sistema e no PHP/Apache para manter a interface e os relatorios no horario esperado.

Tambem e possivel chamar direto:

Instalacao completa:

```bash
cd /opt/flow
sudo ASSTATS_ACTION=install ./install_asstats_ubuntu.sh
```

Aplicar tema e corretivas:

```bash
cd /opt/flow
sudo ASSTATS_ACTION=theme ./install_asstats_ubuntu.sh
```

Adicionar novo roteador:

```bash
cd /opt/flow
sudo ASSTATS_ACTION=add-router ./install_asstats_ubuntu.sh
```

## Atualizacao do repositorio

Se o projeto ja estiver no servidor:

```bash
cd /opt/flow
sudo git pull
sudo chmod +x install_asstats_ubuntu.sh apply_flow_full_customization.sh
```

## Fluxo operacional

O ambiente trabalha com duas trilhas:

`1.` Telemetria principal por ASN

- recebe NetFlow/sFlow
- grava series em `/data/asstats/rrd`
- atualiza `/data/asstats/asstats/asstats_day.txt`

`2.` Telemetria paralela por IP

- agrega por minuto os fluxos observados
- grava `src_ip`, `dst_ip`, `src_asn`, `dst_asn`, `link_tag`, `direction`, `ip_version`, `bytes` e `samples`
- atualiza `/data/asstats/asstats/flow_events.db`

## Rotas web

Depois da instalacao:

```text
http://IP_DO_SERVIDOR/flow/
```

Rotas principais:

- `/flow/`
- `/flow/history.php`
- `/flow/ipsearch.php`
- `/flow/asset.php`
- `/flow/ix.php`
- `/flow/linkusage.php`

## Arquivos gerados no servidor

Principais caminhos do runtime:

```text
/data/asstats/conf/knownlinks
/data/asstats/asstats/asstats_day.txt
/data/asstats/asstats/flow_events.db
/etc/default/asstats
/usr/local/bin/asstats-add-router.sh
```

## Adicionar mais roteadores

Depois da instalacao, voce pode anexar outros exportadores sem reinstalar tudo:

```bash
sudo /usr/local/bin/asstats-add-router.sh /data/asstats
```

Esse utilitario:

- pergunta IP/hostname do novo equipamento
- consulta SNMP
- mostra as interfaces descobertas
- anexa as novas linhas ao `knownlinks`
- reinicia o coletor
- dispara o extrator

## Aplicar em servidor que ja existe

Se o servidor ja estiver em producao e voce quiser apenas aplicar a nova camada `flow`:

```bash
cd /opt/flow
sudo ASSTATS_ACTION=theme ./install_asstats_ubuntu.sh
```

Se quiser aplicar diretamente o script de customizacao:

```bash
cd /opt/flow
sudo ./apply_flow_full_customization.sh /data/asstats flow
```

## Variaveis opcionais

Voce pode sobrescrever os principais parametros:

```bash
sudo ASSTATS_PORT_NETFLOW=9000 \
     ASSTATS_PORT_SFLOW=6343 \
     ASSTATS_MY_ASN=1234 \
     ASSTATS_SAMPLING_RATE=128 \
     ASSTATS_TIMEZONE=America/Fortaleza \
     ASSTATS_WEB_ALIAS=flow \
     ASSTATS_FLOW_RETENTION_DAYS=14 \
     ASSTATS_ACTION=install \
     ./install_asstats_ubuntu.sh
```

Padroes:

- `ASSTATS_PORT_NETFLOW=9000`
- `ASSTATS_PORT_SFLOW=6343`
- `ASSTATS_SAMPLING_RATE=128`
- `ASSTATS_TIMEZONE` usa o timezone atual do servidor como padrao
- `ASSTATS_WEB_ALIAS=flow`
- `ASSTATS_FLOW_RETENTION_DAYS=14`

## Validacoes uteis

Status dos servicos:

```bash
systemctl status asstatsd.service
systemctl status asstats-extract.timer
```

Logs:

```bash
journalctl -u asstatsd.service -n 100 --no-pager
journalctl -u asstats-extract.service -n 100 --no-pager
```

Forcar atualizacao do banco principal:

```bash
systemctl start asstats-extract.service
```

Conferir o banco principal:

```bash
sqlite3 /data/asstats/asstats/asstats_day.txt '.tables'
sqlite3 /data/asstats/asstats/asstats_day.txt 'select count(*) from stats;'
```

Conferir a base paralela por IP:

```bash
sqlite3 /data/asstats/asstats/flow_events.db '.tables'
sqlite3 /data/asstats/asstats/flow_events.db 'select count(*) from flow_events;'
```

Conferir linhas de um exportador no `knownlinks`:

```bash
grep -n 'CDN' /data/asstats/conf/knownlinks
```
