# AS-Stats Ubuntu Installer

Baseado em:

- [Como obter gráficos de tráfego por AS utilizando AS-STATS](https://blog.remontti.com.br/5129)
- [remontti/AS-Stats](https://github.com/remontti/AS-Stats)

## Visão geral

Este projeto automatiza a instalação do AS-Stats em Ubuntu e aplica ajustes práticos para uso real em produção.

O instalador principal é [`install_asstats_ubuntu.sh`](C:/Users/Rocha/Documents/Codex/2026-04-24-voc-consegue-me-ajudar-a-desenvolver/flow/install_asstats_ubuntu.sh).

Além dele, existe um script único para personalização visual da interface já instalada:

- [`apply_flow_full_customization.sh`](C:/Users/Rocha/Documents/Codex/2026-04-24-voc-consegue-me-ajudar-a-desenvolver/flow/apply_flow_full_customization.sh)

## O que o instalador faz

O script:

- detecta a versão do Ubuntu
- habilita `universe` e `multiverse`
- instala dependências do sistema, Perl, PHP, Apache, SNMP e RRD
- clona o `AS-Stats` em `/data/asstats`
- cria `rrd/`, `asstats/`, `conf/` e o diretório web
- pergunta o host exportador e a comunidade SNMP
- executa `snmpwalk` bruto
- descobre interfaces a partir de `ifIndex` e `ifDescr`
- gera o `knownlinks` automaticamente
- grava o `knownlinks` com `TAB` real
- tenta detectar o IP real de origem do flow com `tcpdump`
- corrige a primeira coluna do `knownlinks` quando o IP do flow é diferente do IP consultado por SNMP
- configura a WebUI
- publica a interface em `/flow` por padrão
- cria `systemd service` para `asstatd.pl`
- cria `systemd timer` para `rrd-extractstats.pl`
- aplica a personalização visual futurista diretamente na WebUI

## Como usar

```bash
chmod +x install_asstats_ubuntu.sh
sudo ./install_asstats_ubuntu.sh
```

## Variáveis opcionais

Você pode sobrescrever antes da execução:

```bash
sudo ASSTATS_PORT_NETFLOW=9000 \
     ASSTATS_PORT_SFLOW=6343 \
     ASSTATS_MY_ASN=1234 \
     ASSTATS_SAMPLING_RATE=128 \
     ASSTATS_WEB_ALIAS=flow \
     ./install_asstats_ubuntu.sh
```

Padrões atuais:

- `ASSTATS_PORT_NETFLOW=9000`
- `ASSTATS_PORT_SFLOW=6343`
- `ASSTATS_SAMPLING_RATE=128`
- `ASSTATS_WEB_ALIAS=flow`

## knownlinks

O instalador gera o `knownlinks` automaticamente via SNMP usando:

- `ifIndex`
- `ifDescr`
- `ifAlias` ou `ifName`, quando existir

Formato esperado:

```text
IP_DO_EXPORTADOR<TAB>IFINDEX<TAB>TAG<TAB>DESCRICAO<TAB>CORHEX<TAB>SAMPLING
```

Exemplo:

```text
172.17.1.1	2984	if2984	PTP LINK IP PIX FIBRA	1F78B4	128
```

Pontos importantes:

- use `TAB`, não espaços
- o primeiro campo precisa bater com o IP real de origem do flow
- a sexta coluna precisa bater com o sampling configurado no roteador
- o instalador gera tags seguras como `if49`, `if64`, `if2984`

Arquivo:

```text
/data/asstats/conf/knownlinks
```

## Fluxo de coleta

O processamento acontece em duas etapas:

`1.` `asstatsd.service`

- recebe NetFlow/sFlow
- grava dados brutos em `/data/asstats/rrd`

`2.` `asstats-extract.service`

- roda `rrd-extractstats.pl`
- lê os RRDs
- atualiza o SQLite usado pela interface web

Arquivo SQLite principal:

```text
/data/asstats/asstats/asstats_day.txt
```

## Serviços

Verificar status:

```bash
systemctl status asstatsd.service
systemctl status asstats-extract.timer
```

Logs:

```bash
journalctl -u asstatsd.service -n 100 --no-pager
journalctl -u asstats-extract.service -n 100 --no-pager
```

## Execução manual do extrator

Para forçar atualização do banco usado pela WebUI:

```bash
perl /data/asstats/bin/rrd-extractstats.pl /data/asstats/rrd /data/asstats/conf/knownlinks /data/asstats/asstats/asstats_day.txt
```

Ou:

```bash
systemctl start asstats-extract.service
```

## Acesso web

Depois da instalação:

```text
http://IP_DO_SERVIDOR/flow/
```

## Personalização visual da WebUI

Se o AS-Stats já estiver instalado e você quiser aplicar a personalização sem reinstalar tudo, use:

```bash
chmod +x apply_flow_full_customization.sh
sudo ./apply_flow_full_customization.sh /data/asstats flow
```

Esse script:

- troca o branding para `CECTI Flow Observatory`
- remove o rodapé antigo e usa `personalizado por CECTI`
- muda a URL para `/flow`
- escurece o canvas dos gráficos
- clareia fonte, eixos e grid dos gráficos
- atualiza títulos e rótulos da interface para reduzir a aparência padrão do AS-Stats
- recarrega o Apache
- faz uma checagem simples da linha `CDN` no `knownlinks`

## Observação importante sobre gráficos

Tema visual e coleta são coisas diferentes.

O script de customização resolve:

- aparência da página
- fundo dos gráficos
- legibilidade de textos, eixos e grid

Mas ele não corrige sozinho:

- link sem tráfego exportado
- IPv6 ausente no exportador
- `knownlinks` com `ifIndex` errado
- sampling incompatível
- coleta que oscila por problema no flow de origem

Se um link como `PTP CDN` aparecer sem IPv6 ou “parando e voltando”, o mais comum é problema de exportação/coleta e não do tema.

## Validações úteis

Conferir o `knownlinks`:

```bash
grep -n 'CDN' /data/asstats/conf/knownlinks
```

Conferir se o coletor está ativo:

```bash
systemctl status asstatsd.service
```

Conferir os últimos eventos:

```bash
journalctl -u asstatsd.service -n 80 --no-pager
```

Conferir o banco SQLite:

```bash
sqlite3 /data/asstats/asstats/asstats_day.txt '.tables'
sqlite3 /data/asstats/asstats/asstats_day.txt 'select count(*) from stats;'
```

## Observações finais

- o tutorial original era voltado para Debian; este projeto adapta o fluxo para Ubuntu
- o alias web padrão agora é `flow`
- o sampling padrão do instalador foi ajustado para `128`
- o extrator pode ficar mais lento com o crescimento dos RRDs e do SQLite
- para Mikrotik, o complemento `ip2asn` continua sendo relevante
