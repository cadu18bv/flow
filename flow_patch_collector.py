#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if new in text:
        return text
    if old not in text:
        raise SystemExit(f"Patch failure: snippet not found for {label}")
    return text.replace(old, new, 1)


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit("usage: flow_patch_collector.py /path/to/asstatd.pl")

    target = Path(sys.argv[1])
    text = target.read_text(encoding="utf-8")

    text = replace_once(
        text,
        "use ip2as;\n",
        "use ip2as;\nuse DBI;\n",
        "DBI import",
    )

    text = replace_once(
        text,
        "my $childrunning = 0;\n",
        """my $childrunning = 0;
my $flowdbpath;
my $flowdb_retention_days = 14;
my $flowcache = {};
my $flowcache_lastcleanup = 0;
my $flowcache_cleanup_due = 0;
""",
        "flow globals",
    )

    text = replace_once(
        text,
        "getopts('r:p:P:k:a:nm:', \\%opt);\n",
        "getopts('r:p:P:k:a:nm:q:R:', \\%opt);\n",
        "getopts",
    )

    text = replace_once(
        text,
        "\"\\t-m IP<->ASN mapping\\n\";\n",
        "\"\\t-m IP<->ASN mapping\\n\".\n"
        "\t\"\\t-q <path to flow query SQLite DB>\\n\".\n"
        "\t\"\\t-R <retention in days for flow query DB>\\n\";\n",
        "usage extras",
    )

    text = replace_once(
        text,
        "my $mapping = $opt{'m'};\n",
        """my $mapping = $opt{'m'};
$flowdbpath = $opt{'q'} if defined($opt{'q'});
if (defined($opt{'R'})) {
\t$flowdb_retention_days = $opt{'R'};
\tdie("Flow query DB retention is non numeric\\n") if $flowdb_retention_days !~ /^[0-9]+$/;
}
""",
        "flow options",
    )

    text = replace_once(
        text,
        "# read known links file\nread_knownlinks();\n",
        "# read known links file\nread_knownlinks();\ninit_flowdb() if defined($flowdbpath);\n",
        "flow init",
    )

    helper_block = r"""sub ipv6_bytes_to_string {
	my $packed = shift;
	return undef if !defined($packed);
	my @parts = unpack('n8', $packed);
	return join(':', map { sprintf('%x', $_) } @parts);
}

sub init_flowdb {
	return if !defined($flowdbpath);

	if ($flowdbpath =~ m{^(.*)/[^/]+$}) {
		my $dirname = $1;
		mkdir($dirname) if $dirname ne '' && !-d $dirname;
	}

	my $dbh = DBI->connect("dbi:SQLite:dbname=$flowdbpath", "", "", {
		RaiseError => 1,
		PrintError => 0,
		AutoCommit => 1,
	});

	$dbh->do(q{
		CREATE TABLE IF NOT EXISTS flow_events (
			minute_ts INTEGER NOT NULL,
			router_ip TEXT NOT NULL,
			link_tag TEXT NOT NULL,
			direction TEXT NOT NULL,
			ip_version INTEGER NOT NULL,
			src_ip TEXT NOT NULL,
			dst_ip TEXT NOT NULL,
			src_asn INTEGER NOT NULL,
			dst_asn INTEGER NOT NULL,
			flow_type TEXT NOT NULL,
			bytes INTEGER NOT NULL DEFAULT 0,
			samples INTEGER NOT NULL DEFAULT 0,
			updated_at INTEGER NOT NULL DEFAULT 0,
			PRIMARY KEY (
				minute_ts,
				router_ip,
				link_tag,
				direction,
				ip_version,
				src_ip,
				dst_ip,
				src_asn,
				dst_asn,
				flow_type
			)
		)
	});

	$dbh->do(q{CREATE INDEX IF NOT EXISTS idx_flow_events_src_ip ON flow_events (src_ip, minute_ts)});
	$dbh->do(q{CREATE INDEX IF NOT EXISTS idx_flow_events_dst_ip ON flow_events (dst_ip, minute_ts)});
	$dbh->do(q{CREATE INDEX IF NOT EXISTS idx_flow_events_link_time ON flow_events (link_tag, minute_ts)});
	$dbh->disconnect;
}

sub cache_flow_record {
	my ($routerip, $link_tag, $direction, $ipversion, $srcip, $dstip, $srcas, $dstas, $type, $noctets) = @_;

	return if !defined($flowdbpath);
	return if !defined($srcip) || !defined($dstip);
	return if $srcip eq '' || $dstip eq '';
	return if !defined($link_tag) || $link_tag eq '';

	my $minute_ts = int(time / 60) * 60;
	my $router_ip_txt = inet_ntoa($routerip);
	my $key = join('|', $minute_ts, $router_ip_txt, $link_tag, $direction, $ipversion, $srcip, $dstip, $srcas, $dstas, $type);

	if (!$flowcache->{$key}) {
		$flowcache->{$key} = {
			minute_ts => $minute_ts,
			router_ip => $router_ip_txt,
			link_tag => $link_tag,
			direction => $direction,
			ip_version => $ipversion,
			src_ip => $srcip,
			dst_ip => $dstip,
			src_asn => $srcas,
			dst_asn => $dstas,
			flow_type => $type,
			bytes => 0,
			samples => 0,
			updated_at => time,
		};
	}

	$flowcache->{$key}->{bytes} += $noctets;
	$flowcache->{$key}->{samples}++;
	$flowcache->{$key}->{updated_at} = time;
}

sub flush_flow_cache {
	my $force = shift;
	return if !defined($flowdbpath);
	return if scalar(keys %$flowcache) == 0;

	my $dbh = DBI->connect("dbi:SQLite:dbname=$flowdbpath", "", "", {
		RaiseError => 1,
		PrintError => 0,
		AutoCommit => 0,
	});

	my $sth = $dbh->prepare(q{
		INSERT INTO flow_events (
			minute_ts,
			router_ip,
			link_tag,
			direction,
			ip_version,
			src_ip,
			dst_ip,
			src_asn,
			dst_asn,
			flow_type,
			bytes,
			samples,
			updated_at
		) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
		ON CONFLICT (
			minute_ts,
			router_ip,
			link_tag,
			direction,
			ip_version,
			src_ip,
			dst_ip,
			src_asn,
			dst_asn,
			flow_type
		)
		DO UPDATE SET
			bytes = bytes + excluded.bytes,
			samples = samples + excluded.samples,
			updated_at = excluded.updated_at
	});

	while (my ($entry, $row) = each(%$flowcache)) {
		$sth->execute(
			$row->{minute_ts},
			$row->{router_ip},
			$row->{link_tag},
			$row->{direction},
			$row->{ip_version},
			$row->{src_ip},
			$row->{dst_ip},
			$row->{src_asn},
			$row->{dst_asn},
			$row->{flow_type},
			$row->{bytes},
			$row->{samples},
			$row->{updated_at},
		);
	}

	if ($flowcache_cleanup_due && $flowdb_retention_days > 0) {
		my $cutoff = time - ($flowdb_retention_days * 86400);
		my $cutoff_minute = int($cutoff / 60) * 60;
		$dbh->do("DELETE FROM flow_events WHERE minute_ts < ?", undef, $cutoff_minute);
	}

	$dbh->commit;
	$dbh->disconnect;
}

"""
    text = replace_once(
        text,
        "sub parse_netflow_v5 {\n",
        helper_block + "sub parse_netflow_v5 {\n",
        "helper block",
    )

    text = replace_once(
        text,
        "\t\thandleflow($ipaddr, $flowdata[6], $srcas, $dstas, $flowdata[3], $flowdata[4], 4, 'netflow');\n",
        "\t\thandleflow($ipaddr, $flowdata[6], $srcas, $dstas, $flowdata[3], $flowdata[4], 4, 'netflow', undef, undef, 0, $srcip, $dstip);\n",
        "v5 handleflow",
    )

    text = replace_once(
        text,
        "\t\thandleflow($ipaddr, $flowdata[2], $flowdata[5], $flowdata[6], $flowdata[7], $flowdata[8], 4, 'netflow');\n",
        "\t\thandleflow($ipaddr, $flowdata[2], $flowdata[5], $flowdata[6], $flowdata[7], $flowdata[8], 4, 'netflow', undef, undef, 0, undef, undef);\n",
        "v8 handleflow",
    )

    text = replace_once(
        text,
        "\t\tmy ($inoctets, $outoctets, $srcas, $dstas, $snmpin, $snmpout, $ipversion, $vlanin, $vlanout);\n",
        "\t\tmy ($inoctets, $outoctets, $srcas, $dstas, $snmpin, $snmpout, $ipversion, $vlanin, $vlanout, $srcip, $dstip);\n",
        "v9 vars",
    )
    text = replace_once(
        text,
        "\t\t$inoctets = 0;\n\t\t$outoctets = 0;\n\t\t$ipversion = 4;\n",
        "\t\t$inoctets = 0;\n\t\t$outoctets = 0;\n\t\t$ipversion = 4;\n\t\t$srcip = undef;\n\t\t$dstip = undef;\n",
        "v9 init",
    )
    text = replace_once(
        text,
        """\t\t\t} elsif ($cur_fldtype == 60) {\t# IP_PROTOCOL_VERSION
\t\t\t\t$ipversion = unpack("C", $cur_fldval);
\t\t\t} elsif ($cur_fldtype == 27 || $cur_fldtype == 28) {\t# IPV6_SRC_ADDR/IPV6_DST_ADDR
\t\t\t\t$ipversion = 6;
""",
        """\t\t\t} elsif ($cur_fldtype == 8) {\t# IPV4_SRC_ADDR
\t\t\t\t$srcip = join '.', unpack('C4', $cur_fldval);
\t\t\t} elsif ($cur_fldtype == 12) {\t# IPV4_DST_ADDR
\t\t\t\t$dstip = join '.', unpack('C4', $cur_fldval);
\t\t\t} elsif ($cur_fldtype == 27) {\t# IPV6_SRC_ADDR
\t\t\t\t$ipversion = 6;
\t\t\t\t$srcip = ipv6_bytes_to_string($cur_fldval);
\t\t\t} elsif ($cur_fldtype == 28) {\t# IPV6_DST_ADDR
\t\t\t\t$ipversion = 6;
\t\t\t\t$dstip = ipv6_bytes_to_string($cur_fldval);
\t\t\t} elsif ($cur_fldtype == 60) {\t# IP_PROTOCOL_VERSION
\t\t\t\t$ipversion = unpack("C", $cur_fldval);
""",
        "v9 ip fields",
    )
    text = replace_once(
        text,
        "\t\tif (defined($srcas) && defined($dstas) && defined($snmpin) && defined($snmpout)) {\n\t\t\thandleflow($ipaddr, $inoctets + $outoctets, $srcas, $dstas, $snmpin, $snmpout, $ipversion, 'netflow', $vlanin, $vlanout);\n\t\t}\n",
        "\t\tif (defined($srcas) && defined($dstas) && defined($snmpin) && defined($snmpout)) {\n\t\t\thandleflow($ipaddr, $inoctets + $outoctets, $srcas, $dstas, $snmpin, $snmpout, $ipversion, 'netflow', $vlanin, $vlanout, 0, $srcip, $dstip);\n\t\t}\n",
        "v9 handleflow",
    )

    text = replace_once(
        text,
        "\t\tmy ($inoctets, $outoctets, $srcas, $dstas, $snmpin, $snmpout, $ipversion, $vlanin, $vlanout);\n",
        "\t\tmy ($inoctets, $outoctets, $srcas, $dstas, $snmpin, $snmpout, $ipversion, $vlanin, $vlanout, $srcip, $dstip);\n",
        "v10 vars",
    )
    text = replace_once(
        text,
        "\t\t$inoctets = 0;\n\t\t$outoctets = 0;\n\t\t$ipversion = 4;\n",
        "\t\t$inoctets = 0;\n\t\t$outoctets = 0;\n\t\t$ipversion = 4;\n\t\t$srcip = undef;\n\t\t$dstip = undef;\n",
        "v10 init",
    )
    text = replace_once(
        text,
        """\t\t\t} elsif ($cur_fldtype == 60) {\t# IP_PROTOCOL_VERSION
\t\t\t\t$ipversion = unpack("C", $cur_fldval);
\t\t\t} elsif ($cur_fldtype == 27 || $cur_fldtype == 28) {\t# IPV6_SRC_ADDR/IPV6_DST_ADDR
\t\t\t\t$ipversion = 6;
""",
        """\t\t\t} elsif ($cur_fldtype == 8) {\t# IPV4_SRC_ADDR
\t\t\t\t$srcip = join '.', unpack('C4', $cur_fldval);
\t\t\t} elsif ($cur_fldtype == 12) {\t# IPV4_DST_ADDR
\t\t\t\t$dstip = join '.', unpack('C4', $cur_fldval);
\t\t\t} elsif ($cur_fldtype == 27) {\t# IPV6_SRC_ADDR
\t\t\t\t$ipversion = 6;
\t\t\t\t$srcip = ipv6_bytes_to_string($cur_fldval);
\t\t\t} elsif ($cur_fldtype == 28) {\t# IPV6_DST_ADDR
\t\t\t\t$ipversion = 6;
\t\t\t\t$dstip = ipv6_bytes_to_string($cur_fldval);
\t\t\t} elsif ($cur_fldtype == 60) {\t# IP_PROTOCOL_VERSION
\t\t\t\t$ipversion = unpack("C", $cur_fldval);
""",
        "v10 ip fields",
    )
    text = replace_once(
        text,
        "\t\tif (defined($srcas) && defined($dstas) && defined($snmpin) && defined($snmpout)) {\n\t\t\thandleflow($ipaddr, $inoctets + $outoctets, $srcas, $dstas, $snmpin, $snmpout, $ipversion, 'netflow', $vlanin, $vlanout);\n\t\t}\n",
        "\t\tif (defined($srcas) && defined($dstas) && defined($snmpin) && defined($snmpout)) {\n\t\t\thandleflow($ipaddr, $inoctets + $outoctets, $srcas, $dstas, $snmpin, $snmpout, $ipversion, 'netflow', $vlanin, $vlanout, 0, $srcip, $dstip);\n\t\t}\n",
        "v10 handleflow",
    )

    text = replace_once(
        text,
        "\t\thandleflow($ipaddr, $noctets, $srcas, $dstas, $snmpin, $snmpout, $ipversion, 'sflow', $vlanin, $vlanout);\n",
        "\t\thandleflow($ipaddr, $noctets, $srcas, $dstas, $snmpin, $snmpout, $ipversion, 'sflow', $vlanin, $vlanout, 0, $srcip, $dstip);\n",
        "sflow handleflow",
    )
    text = replace_once(
        text,
        "\t\t\t    handleflow($ipaddr, $noctets, $srcpeeras, $dstpeeras, $snmpin, $snmpout, $ipversion, 'sflow', $vlanin, $vlanout, 1);\n",
        "\t\t\t    handleflow($ipaddr, $noctets, $srcpeeras, $dstpeeras, $snmpin, $snmpout, $ipversion, 'sflow', $vlanin, $vlanout, 1, $srcip, $dstip);\n",
        "sflow peeras",
    )

    text = replace_once(
        text,
        "sub handleflow {\n\tmy ($routerip, $noctets, $srcas, $dstas, $snmpin, $snmpout, $ipversion, $type, $vlanin, $vlanout, $peeras) = @_;\n",
        "sub handleflow {\n\tmy ($routerip, $noctets, $srcas, $dstas, $snmpin, $snmpout, $ipversion, $type, $vlanin, $vlanout, $peeras, $srcip, $dstip) = @_;\n",
        "handleflow signature",
    )
    text = replace_once(
        text,
        "\t\thandleflow($routerip, $noctets, $srcas, 0, $snmpin, $snmpout, $ipversion, $type, $vlanin, $vlanout, $peeras);\n\t\thandleflow($routerip, $noctets,\t0, $dstas, $snmpin, $snmpout, $ipversion, $type, $vlanin, $vlanout, $peeras);\n",
        "\t\thandleflow($routerip, $noctets, $srcas, 0, $snmpin, $snmpout, $ipversion, $type, $vlanin, $vlanout, $peeras, $srcip, $dstip);\n\t\thandleflow($routerip, $noctets,\t0, $dstas, $snmpin, $snmpout, $ipversion, $type, $vlanin, $vlanout, $peeras, $srcip, $dstip);\n",
        "handleflow recursion",
    )
    text = replace_once(
        text,
        "\tif (!$ifalias) {\n\t\t# ignore this, as it's through an interface we don't monitor\n\t\treturn;\n\t}\n\t\n\tmy $dsname;\n",
        "\tif (!$ifalias) {\n\t\t# ignore this, as it's through an interface we don't monitor\n\t\treturn;\n\t}\n\t\n\tif (!$peeras) {\n\t\tcache_flow_record($routerip, $ifalias, $direction, $ipversion, $srcip, $dstip, $srcas, $dstas, $type, $noctets);\n\t}\n\t\n\tmy $dsname;\n",
        "flow cache hook",
    )

    text = replace_once(
        text,
        "\t$childrunning = 1;\n\tmy $pid = fork();\n",
        "\tif (defined($flowdbpath) && $flowdb_retention_days > 0 && ((time - $flowcache_lastcleanup) > 3600)) {\n\t\t$flowcache_lastcleanup = time;\n\t\t$flowcache_cleanup_due = 1;\n\t} else {\n\t\t$flowcache_cleanup_due = 0;\n\t}\n\n\t$childrunning = 1;\n\tmy $pid = fork();\n",
        "cleanup schedule",
    )
    text = replace_once(
        text,
        "\t\tif(!defined($force)){\n\t\t\tfor (keys %$ascache) {\n",
        "\t\tif(!defined($force)){\n\t\t\t$flowcache = {};\n\t\t\tfor (keys %$ascache) {\n",
        "parent clears flow cache",
    )
    text = replace_once(
        text,
        "\t\t}else{\n\t\t\t$ascache = ();\n\t\t}\n",
        "\t\t}else{\n\t\t\t$ascache = {};\n\t\t\t$flowcache = {};\n\t\t}\n",
        "force clear caches",
    )
    text = replace_once(
        text,
        "\twhile (my ($entry, $cacheent) = each(%$ascache)) {\n",
        "\tflush_flow_cache($force);\n\n\twhile (my ($entry, $cacheent) = each(%$ascache)) {\n",
        "flush flow cache in child",
    )

    target.write_text(text, encoding="utf-8")


if __name__ == "__main__":
    main()
