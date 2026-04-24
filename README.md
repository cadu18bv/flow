# AS-Stats Ubuntu Installer

Baseado no tutorial:

- [Como obter gráficos de tráfego por AS utilizando AS-STATS](https://blog.remontti.com.br/5129)

E no projeto:

- [remontti/AS-Stats](https://github.com/remontti/AS-Stats)

## O que este instalador faz

O script [`install_asstats_ubuntu.sh`](C:\Users\Rocha\Documents\Codex\2026-04-24-voc-consegue-me-ajudar-a-desenvolver\flow\install_asstats_ubuntu.sh) foi feito para Ubuntu e:

- detecta a versão do Ubuntu
- habilita `universe` e `multiverse`
- instala os pacotes do tutorial com pequenas corretivas
- instala os módulos Perl via `cpanm`
- clona o `AS-Stats` em `/data/asstats`
- cria `rrd/`, `asstats/` e o `knownlinks`
- pergunta qual host exporta flow
- pergunta os dados do `SNMP`
- consulta automaticamente as interfaces ativas via `snmpwalk`
- preenche o `knownlinks` automaticamente
- configura um alias web em `/var/www/html/as-stats`
- cria `systemd service` para `asstatd.pl`
- cria `systemd timer` para `rrd-extractstats.pl`

## Como usar

```bash
chmod +x install_asstats_ubuntu.sh
sudo ./install_asstats_ubuntu.sh
```

## Variáveis opcionais

Você pode sobrescrever antes de executar:

```bash
sudo ASSTATS_PORT_NETFLOW=9000 \
     ASSTATS_PORT_SFLOW=6343 \
     ASSTATS_MY_ASN=1234 \
     ASSTATS_WEB_ALIAS=as-stats \
     ./install_asstats_ubuntu.sh
```

Ou deixar o script perguntar interativamente:

- IP/hostname do exportador
- versão SNMP
- comunidade SNMP
- porta SNMP
- sampling rate

## O que ainda precisa ser ajustado manualmente

O instalador deixa a base pronta, mas estes pontos continuam dependendo do seu ambiente:

### 1. `knownlinks`

O script agora gera o arquivo automaticamente via `SNMP`, usando:

- `ifDescr`
- `ifAlias`, quando existir
- `ifOperStatus`

Ele inclui apenas interfaces ativas no momento da coleta.

Mesmo assim, vale revisar:

```text
/data/asstats/conf/knownlinks
```

Formato:

```text
IP_DO_EXPORTADOR<TAB>IFINDEX<TAB>TAG<TAB>DESCRICAO<TAB>CORHEX<TAB>SAMPLING
```

Exemplo:

```text
10.20.30.2	6	uplink01	Uplink Principal	1F78B4	1
```

Importante:

- use `TAB`, não espaços
- o `TAG` deve ser curto

### 2. ASN local

O script tenta ajustar `config.inc` com o valor de `ASSTATS_MY_ASN`, mas vale confirmar:

```text
/data/asstats/www/config.inc
```

### 3. Exportação de flow no roteador

O servidor sozinho não gera gráfico. O roteador precisa enviar:

- NetFlow v8/v9 AS aggregation para `PORTA 9000/udp`
- ou sFlow para `6343/udp`

### 4. Descobrir `ifIndex` manualmente, se precisar

Se quiser conferir ou refazer:

```bash
snmpwalk -v2c -c SUA_COMMUNITY IP_DO_ROUTER IF-MIB::ifDescr
snmpwalk -v2c -c SUA_COMMUNITY IP_DO_ROUTER IF-MIB::ifIndex
snmpwalk -v2c -c SUA_COMMUNITY IP_DO_ROUTER IF-MIB::ifOperStatus
```

## Serviços

Verificar:

```bash
systemctl status asstatsd.service
systemctl status asstats-extract.timer
```

Logs:

```bash
journalctl -u asstatsd.service -n 100 --no-pager
```

## Execução manual do extrator

Para gerar o arquivo diário inicialmente:

```bash
perl /data/asstats/bin/rrd-extractstats.pl /data/asstats/rrd /data/asstats/conf/knownlinks /data/asstats/asstats/asstats_day.txt
```

## Acesso web

Depois:

```text
http://IP_DO_SERVIDOR/as-stats
```

## Observações

- o tutorial original foi escrito para Debian 11; este script adapta o processo para Ubuntu
- se o serviço `asstatsd` não subir, o caso mais comum é `knownlinks` vazio ou mal formatado
- para Mikrotik, o complemento `ip2asn` continua sendo relevante
