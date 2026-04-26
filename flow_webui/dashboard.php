<?php
require_once("func.inc");
require_once("flow_ui.php");

function flow_dashboard_runtime_dir() {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime';
}

function flow_dashboard_local_profiles_path() {
    return flow_dashboard_runtime_dir() . DIRECTORY_SEPARATOR . 'dashboard-local-cdn.json';
}

function flow_dashboard_local_profiles() {
    static $profiles = null;
    if ($profiles !== null) {
        return $profiles;
    }

    $profiles = array();
    $path = flow_dashboard_local_profiles_path();
    if (!is_file($path)) {
        return $profiles;
    }

    $content = @file_get_contents($path);
    if ($content === false || trim($content) === '') {
        return $profiles;
    }

    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        return $profiles;
    }

    foreach ($decoded as $key => $profile) {
        if (!is_array($profile)) {
            continue;
        }
        $profiles[(string)$key] = array(
            'links' => isset($profile['links']) && is_array($profile['links']) ? array_values(array_unique(array_map('strval', $profile['links']))) : array(),
            'remote_asns' => isset($profile['remote_asns']) && is_array($profile['remote_asns']) ? array_values(array_unique(array_map('intval', $profile['remote_asns']))) : array(),
            'local_asns' => isset($profile['local_asns']) && is_array($profile['local_asns']) ? array_values(array_unique(array_map('intval', $profile['local_asns']))) : array(),
            'prefixes' => isset($profile['prefixes']) && is_array($profile['prefixes']) ? array_values(array_unique(array_map('strval', $profile['prefixes']))) : array(),
            'note' => isset($profile['note']) ? trim((string)$profile['note']) : '',
        );
    }

    return $profiles;
}

function flow_dashboard_providers() {
    return array(
        array('key' => 'netflix', 'label' => 'Netflix', 'eyebrow' => 'streaming core', 'asns' => array(2906, 40027, 55095, 394406), 'local_title' => 'Netflix local'),
        array('key' => 'facebook', 'label' => 'Meta', 'eyebrow' => 'social delivery', 'asns' => array(32934, 63293), 'local_title' => 'Meta local'),
        array('key' => 'google', 'label' => 'Google', 'eyebrow' => 'search + video', 'asns' => array(15169, 36040, 139070), 'local_title' => 'Google local'),
        array('key' => 'akamai', 'label' => 'Akamai', 'eyebrow' => 'cdn edge', 'asns' => array(20940, 16625), 'local_title' => 'Akamai local'),
        array('key' => 'amazon', 'label' => 'Amazon', 'eyebrow' => 'cloud platform', 'asns' => array(16509, 14618, 38895, 8987), 'local_title' => 'Amazon local'),
        array('key' => 'microsoft', 'label' => 'Microsoft', 'eyebrow' => 'cloud + enterprise', 'asns' => array(8075, 8068, 12076), 'local_title' => 'Microsoft local'),
    );
}

function flow_dashboard_db_open() {
    $error = null;
    $db = flow_events_open_connection($error);
    if ($db) {
        @$db->createFunction('flow_dashboard_ip_match', 'flow_dashboard_ip_matches_filter', 2);
        return $db;
    }
    return null;
}

function flow_dashboard_ip_matches_filter($candidate, $filter) {
    $candidate = trim((string)$candidate);
    $filter = trim((string)$filter);
    if ($candidate === '' || $filter === '') {
        return 0;
    }
    if (strpos($filter, '/') === false) {
        return $candidate === $filter ? 1 : 0;
    }

    list($network, $prefix) = array_pad(explode('/', $filter, 2), 2, null);
    if (!ctype_digit((string)$prefix)) {
        return 0;
    }
    $candidateBin = @inet_pton($candidate);
    $networkBin = @inet_pton($network);
    if ($candidateBin === false || $networkBin === false || strlen($candidateBin) !== strlen($networkBin)) {
        return 0;
    }

    $bits = (int)$prefix;
    $maxBits = strlen($candidateBin) * 8;
    if ($bits < 0 || $bits > $maxBits) {
        return 0;
    }
    $bytes = intdiv($bits, 8);
    $remainder = $bits % 8;
    if ($bytes > 0 && substr($candidateBin, 0, $bytes) !== substr($networkBin, 0, $bytes)) {
        return 0;
    }
    if ($remainder === 0) {
        return 1;
    }

    $mask = (0xff << (8 - $remainder)) & 0xff;
    return ((ord($candidateBin[$bytes]) & $mask) === (ord($networkBin[$bytes]) & $mask)) ? 1 : 0;
}

function flow_dashboard_in_clause($values, $prefix) {
    if (empty($values)) {
        return '';
    }

    $placeholders = array();
    foreach (array_values($values) as $index => $value) {
        $placeholders[] = ':' . $prefix . $index;
    }

    return implode(', ', $placeholders);
}

function flow_dashboard_bind_in_clause($stmt, $values, $prefix, $type) {
    foreach (array_values($values) as $index => $value) {
        $stmt->bindValue(':' . $prefix . $index, $value, $type);
    }
}

function flow_dashboard_sum_query($db, $conditions, $bindings, $hours) {
    $sql = "
        SELECT ip_version, lower(direction) AS direction, SUM(bytes) AS total_bytes
        FROM flow_events
        WHERE minute_ts >= :start
    ";

    if (!empty($conditions)) {
        $sql .= ' AND ' . implode(' AND ', $conditions);
    }

    $sql .= ' GROUP BY ip_version, lower(direction)';

    $stmt = @$db->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bindValue(':start', time() - ((int)$hours * 3600), SQLITE3_INTEGER);
    foreach ($bindings as $binding) {
        $stmt->bindValue($binding['name'], $binding['value'], $binding['type']);
    }

    $result = @$stmt->execute();
    if ($result === false) {
        return false;
    }

    $totals = array(0.0, 0.0, 0.0, 0.0);
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $isIpv6 = ((int)$row['ip_version'] === 6);
        $isOut = ((string)$row['direction'] === 'out');
        if (!$isIpv6 && !$isOut) {
            $totals[0] += (float)$row['total_bytes'];
        } elseif (!$isIpv6 && $isOut) {
            $totals[1] += (float)$row['total_bytes'];
        } elseif ($isIpv6 && !$isOut) {
            $totals[2] += (float)$row['total_bytes'];
        } else {
            $totals[3] += (float)$row['total_bytes'];
        }
    }

    return $totals;
}

function flow_dashboard_group_usage($provider, $statsfile, $selectedLinks) {
    $cachePayload = array(
        'provider' => isset($provider['key']) ? (string)$provider['key'] : '',
        'statsfile' => (string)$statsfile,
        'links' => array_values((array)$selectedLinks),
    );
    $cacheHit = false;
    $cached = flow_cache_get('dashboard_group_usage', $cachePayload, 30, $cacheHit);
    if ($cacheHit) {
        return is_array($cached) ? $cached : array(
            'totals' => array(0, 0, 0, 0),
            'members' => array(),
            'dominant_asn' => 0,
            'dominant_usage' => array(0, 0, 0, 0),
        );
    }

    $asns = isset($provider['asns']) && is_array($provider['asns']) ? $provider['asns'] : array();
    $asns = array_values(array_unique(array_map('intval', $asns)));
    if (empty($asns)) {
        return array(
            'totals' => array(0, 0, 0, 0),
            'members' => array(),
            'dominant_asn' => 0,
            'dominant_usage' => array(0, 0, 0, 0),
        );
    }

    $rows = getasstats_top(max(50, count($asns)), $statsfile, $selectedLinks, $asns);
    $totals = array(0, 0, 0, 0);
    $members = array();
    $dominantAsn = 0;
    $dominantScore = -1;
    $dominantUsage = array(0, 0, 0, 0);

    foreach ($asns as $asn) {
        $usage = isset($rows[$asn]) ? $rows[$asn] : array(0, 0, 0, 0);
        $usage = array_values(array_pad($usage, 4, 0));
        for ($i = 0; $i < 4; $i++) {
            $totals[$i] += isset($usage[$i]) ? (float)$usage[$i] : 0;
        }

        $score = array_sum($usage);
        if ($score > 0) {
            $members[] = array('asn' => $asn, 'usage' => $usage, 'score' => $score);
        }

        if ($score > $dominantScore) {
            $dominantScore = $score;
            $dominantAsn = $asn;
            $dominantUsage = $usage;
        }
    }

    usort($members, function ($left, $right) {
        if ($left['score'] === $right['score']) {
            return $left['asn'] <=> $right['asn'];
        }
        return ($left['score'] > $right['score']) ? -1 : 1;
    });

    $result = array(
        'totals' => $totals,
        'members' => $members,
        'dominant_asn' => $dominantAsn,
        'dominant_usage' => $dominantUsage,
    );
    flow_cache_set('dashboard_group_usage', $cachePayload, $result);
    return $result;
}

function flow_dashboard_local_cdn_usage($provider, $hours, $selectedLinks) {
    $cachePayload = array(
        'provider' => isset($provider['key']) ? (string)$provider['key'] : '',
        'hours' => (int)$hours,
        'links' => array_values((array)$selectedLinks),
        'backend' => flow_events_backend(),
    );
    $cacheHit = false;
    $cached = flow_cache_get('dashboard_local_cdn', $cachePayload, 25, $cacheHit);
    if ($cacheHit) {
        return is_array($cached) ? $cached : array(
            'status' => 'indisponivel',
            'classified_totals' => array(0, 0, 0, 0),
            'pool_totals' => array(0, 0, 0, 0),
            'shared_totals' => array(0, 0, 0, 0),
            'links' => array(),
            'remote_asns' => array(),
            'prefixes' => array(),
            'note' => 'Cache invalido para CDN local.',
        );
    }

    $profiles = flow_dashboard_local_profiles();
    $key = isset($provider['key']) ? (string)$provider['key'] : '';
    if ($key === '' || !isset($profiles[$key])) {
        $result = array(
            'status' => 'nao_mapeado',
            'classified_totals' => array(0, 0, 0, 0),
            'pool_totals' => array(0, 0, 0, 0),
            'shared_totals' => array(0, 0, 0, 0),
            'links' => array(),
            'remote_asns' => array(),
            'prefixes' => array(),
            'note' => 'Sem perfil explicito de CDN local. O dashboard nao infere cache local automaticamente para nao misturar CDN terceiro, transito ou entrega remota.',
        );
        flow_cache_set('dashboard_local_cdn', $cachePayload, $result);
        return $result;
    }

    $profile = $profiles[$key];
    if (empty($profile['links']) && empty($profile['remote_asns']) && empty($profile['local_asns']) && empty($profile['prefixes'])) {
        $result = array(
            'status' => 'incompleto',
            'classified_totals' => array(0, 0, 0, 0),
            'pool_totals' => array(0, 0, 0, 0),
            'shared_totals' => array(0, 0, 0, 0),
            'links' => array(),
            'remote_asns' => array(),
            'prefixes' => array(),
            'note' => 'O perfil local existe, mas ainda nao possui assinatura suficiente para uma classificacao confiavel.',
        );
        flow_cache_set('dashboard_local_cdn', $cachePayload, $result);
        return $result;
    }

    $linksFilter = !empty($selectedLinks) ? array_values(array_intersect($profile['links'], $selectedLinks)) : $profile['links'];
    if (!empty($profile['links']) && empty($linksFilter)) {
        $result = array(
            'status' => 'fora_do_filtro',
            'classified_totals' => array(0, 0, 0, 0),
            'pool_totals' => array(0, 0, 0, 0),
            'shared_totals' => array(0, 0, 0, 0),
            'links' => $profile['links'],
            'remote_asns' => $profile['remote_asns'],
            'prefixes' => $profile['prefixes'],
            'note' => 'Os links homologados para esse CDN local ficaram fora do filtro atual.',
        );
        flow_cache_set('dashboard_local_cdn', $cachePayload, $result);
        return $result;
    }

    $db = flow_dashboard_db_open();
    if (!$db) {
        $result = array(
            'status' => 'indisponivel',
            'classified_totals' => array(0, 0, 0, 0),
            'pool_totals' => array(0, 0, 0, 0),
            'shared_totals' => array(0, 0, 0, 0),
            'links' => $profile['links'],
            'remote_asns' => $profile['remote_asns'],
            'prefixes' => $profile['prefixes'],
            'note' => 'A base flow_events.db nao esta disponivel para validar o CDN local.',
        );
        flow_cache_set('dashboard_local_cdn', $cachePayload, $result);
        return $result;
    }

    $poolConditions = array();
    $poolBindings = array();
    if (!empty($linksFilter)) {
        $poolConditions[] = 'link_tag IN (' . flow_dashboard_in_clause($linksFilter, 'link_') . ')';
        foreach (array_values($linksFilter) as $index => $linkTag) {
            $poolBindings[] = array('name' => ':link_' . $index, 'value' => (string)$linkTag, 'type' => SQLITE3_TEXT);
        }
    }
    if (!empty($profile['local_asns'])) {
        $poolConditions[] = '(src_asn IN (' . flow_dashboard_in_clause($profile['local_asns'], 'local_asn_') . ') OR dst_asn IN (' . flow_dashboard_in_clause($profile['local_asns'], 'local_asn_') . '))';
        foreach (array_values($profile['local_asns']) as $index => $asn) {
            $poolBindings[] = array('name' => ':local_asn_' . $index, 'value' => (int)$asn, 'type' => SQLITE3_INTEGER);
        }
    }

    $poolTotals = flow_dashboard_sum_query($db, $poolConditions, $poolBindings, $hours);
    if ($poolTotals === false) {
        $db->close();
        $result = array(
            'status' => 'indisponivel',
            'classified_totals' => array(0, 0, 0, 0),
            'pool_totals' => array(0, 0, 0, 0),
            'shared_totals' => array(0, 0, 0, 0),
            'links' => $profile['links'],
            'remote_asns' => $profile['remote_asns'],
            'prefixes' => $profile['prefixes'],
            'note' => 'Nao foi possivel ler a malha de CDN compartilhada nesta janela.',
        );
        flow_cache_set('dashboard_local_cdn', $cachePayload, $result);
        return $result;
    }

    $classifiedConditions = $poolConditions;
    $classifiedBindings = $poolBindings;
    $signatureConditions = array();
    if (!empty($profile['remote_asns'])) {
        $signatureConditions[] = '(src_asn IN (' . flow_dashboard_in_clause($profile['remote_asns'], 'remote_asn_') . ') OR dst_asn IN (' . flow_dashboard_in_clause($profile['remote_asns'], 'remote_asn_') . '))';
        foreach (array_values($profile['remote_asns']) as $index => $asn) {
            $classifiedBindings[] = array('name' => ':remote_asn_' . $index, 'value' => (int)$asn, 'type' => SQLITE3_INTEGER);
        }
    }
    if (!empty($profile['prefixes'])) {
        $prefixParts = array();
        foreach (array_values($profile['prefixes']) as $index => $prefix) {
            $binding = ':cdn_prefix_' . $index;
            $prefixParts[] = '(flow_dashboard_ip_match(src_ip, ' . $binding . ') = 1 OR flow_dashboard_ip_match(dst_ip, ' . $binding . ') = 1)';
            $classifiedBindings[] = array('name' => $binding, 'value' => (string)$prefix, 'type' => SQLITE3_TEXT);
        }
        $signatureConditions[] = '(' . implode(' OR ', $prefixParts) . ')';
    }
    if (!empty($signatureConditions)) {
        $classifiedConditions[] = '(' . implode(' OR ', $signatureConditions) . ')';
    }

    $classifiedTotals = empty($signatureConditions)
        ? array(0, 0, 0, 0)
        : flow_dashboard_sum_query($db, $classifiedConditions, $classifiedBindings, $hours);
    $db->close();
    if ($classifiedTotals === false) {
        $classifiedTotals = array(0, 0, 0, 0);
    }

    $sharedTotals = array(0.0, 0.0, 0.0, 0.0);
    for ($i = 0; $i < 4; $i++) {
        $sharedTotals[$i] = max(0.0, (float)$poolTotals[$i] - (float)$classifiedTotals[$i]);
    }

    $status = 'sem_trafego';
    if (array_sum($classifiedTotals) > 0) {
        $status = 'validado';
    } elseif (array_sum($sharedTotals) > 0) {
        $status = 'compartilhado';
    } elseif (array_sum($poolTotals) > 0) {
        $status = 'pool_sem_assinatura';
    }

    $result = array(
        'status' => $status,
        'classified_totals' => $classifiedTotals,
        'pool_totals' => $poolTotals,
        'shared_totals' => $sharedTotals,
        'links' => $profile['links'],
        'remote_asns' => $profile['remote_asns'],
        'prefixes' => $profile['prefixes'],
        'note' => $profile['note'] !== '' ? $profile['note'] : 'CDN local contabilizado apenas por perfil explicito de links e/ou ASN homologados.',
    );
    flow_cache_set('dashboard_local_cdn', $cachePayload, $result);
    return $result;
}

function flow_dashboard_member_badges($members) {
    if (empty($members)) {
        return '<div class="flow-provider-members"><span class="flow-pill">Sem ASN ativo na janela</span></div>';
    }

    $items = array();
    foreach (array_slice($members, 0, 6) as $member) {
        $items[] = '<span class="flow-pill">AS' . htmlspecialchars((string)$member['asn']) . '</span>';
    }

    return '<div class="flow-provider-members">' . implode('', $items) . '</div>';
}

function flow_render_dashboard_provider_card($provider, $hours, $start, $end, $selectedLinks, $peerusage, $showv6, $statsfile) {
    $group = flow_dashboard_group_usage($provider, $statsfile, $selectedLinks);
    $local = flow_dashboard_local_cdn_usage($provider, $hours, $selectedLinks);
    $dominantAsn = (int)$group['dominant_asn'];
    $dominantInfo = $dominantAsn > 0 ? flow_enrich_as_info($dominantAsn, getASInfo($dominantAsn)) : array('descr' => $provider['label']);
    $dominantDescr = isset($dominantInfo['descr']) && trim((string)$dominantInfo['descr']) !== '' ? $dominantInfo['descr'] : $provider['label'];

    $series4 = $dominantAsn > 0 ? flow_fetch_as_minute_series($dominantAsn, 4, $hours, $selectedLinks) : array();
    $series6 = ($showv6 && $dominantAsn > 0) ? flow_fetch_as_minute_series($dominantAsn, 6, $hours, $selectedLinks) : array();
    $graph4 = $dominantAsn > 0
        ? flow_render_link_svg_chart($series4, 'dash-' . $provider['key'] . '-v4', $dominantDescr . ' - IPv4')
        : flow_render_empty_state('Sem grafico', 'Nenhum ASN do grupo gerou amostras nessa janela.');
    $graph6 = ($showv6 && $dominantAsn > 0)
        ? flow_render_link_svg_chart($series6, 'dash-' . $provider['key'] . '-v6', $dominantDescr . ' - IPv6')
        : '';
    $stats4 = $dominantAsn > 0 ? flow_stats_from_minute_series($series4) : null;
    $stats6 = ($showv6 && $dominantAsn > 0) ? flow_stats_from_minute_series($series6) : null;

    $totals = $group['totals'];
    $memberCount = count($group['members']);

    $remoteIn4 = format_bytes((float)$totals[0]);
    $remoteOut4 = format_bytes((float)$totals[1]);
    $remoteIn6 = format_bytes((float)$totals[2]);
    $remoteOut6 = format_bytes((float)$totals[3]);
    $localIn4 = format_bytes((float)$local['classified_totals'][0]);
    $localOut4 = format_bytes((float)$local['classified_totals'][1]);
    $localIn6 = format_bytes((float)$local['classified_totals'][2]);
    $localOut6 = format_bytes((float)$local['classified_totals'][3]);
    $sharedIn4 = format_bytes((float)$local['shared_totals'][0]);
    $sharedOut4 = format_bytes((float)$local['shared_totals'][1]);
    $sharedIn6 = format_bytes((float)$local['shared_totals'][2]);
    $sharedOut6 = format_bytes((float)$local['shared_totals'][3]);
    $localLinks = empty($local['links']) ? 'nao mapeado' : implode(', ', $local['links']);
    $localAsns = empty($local['remote_asns']) ? 'nao mapeado' : implode(', ', array_map(function ($asn) { return 'AS' . (int)$asn; }, $local['remote_asns']));
    $localPrefixes = empty($local['prefixes']) ? 'nao mapeado' : implode(', ', $local['prefixes']);

    $html = '<article class="flow-provider-card flow-provider-' . htmlspecialchars($provider['key']) . '">';
    $html .= '<header class="flow-provider-head">';
    $html .= '<div class="flow-provider-copy">';
    $html .= '<span class="flow-eyebrow">' . htmlspecialchars($provider['eyebrow']) . '</span>';
    $html .= '<h3>' . htmlspecialchars($provider['label']) . '</h3>';
    $html .= '<p>Grupo com ' . htmlspecialchars((string)count($provider['asns'])) . ' ASN mapeados â€¢ ' . htmlspecialchars((string)$memberCount) . ' ativos na janela</p>';
    $html .= '</div>';
    $html .= '<div class="flow-provider-actions">';
    if ($dominantAsn > 0) {
        $html .= '<a class="flow-button flow-button-ghost" href="history.php?as=' . urlencode((string)$dominantAsn) . '">Abrir ASN dominante</a>';
    }
    $html .= '</div>';
    $html .= '</header>';
    $html .= flow_dashboard_member_badges($group['members']);
    $html .= '<div class="flow-provider-split">';

    $html .= '<section class="flow-provider-slice">';
    $html .= '<header><strong>Entrega remota / backbone</strong><span>agrupamento por ASN do provedor</span></header>';
    $html .= '<div class="flow-provider-micro">';
    $html .= '<span>IPv4 IN grupo ' . htmlspecialchars($remoteIn4) . '</span>';
    $html .= '<span>IPv4 OUT grupo ' . htmlspecialchars($remoteOut4) . '</span>';
    if ($showv6) {
        $html .= '<span>IPv6 IN grupo ' . htmlspecialchars($remoteIn6) . '</span>';
        $html .= '<span>IPv6 OUT grupo ' . htmlspecialchars($remoteOut6) . '</span>';
    }
    if ($dominantAsn > 0) {
        $html .= '<span>ASN dominante AS' . htmlspecialchars((string)$dominantAsn) . '</span>';
    }
    $html .= '</div>';
    $html .= '<div class="flow-provider-note">Grafico de referencia baseado no ASN dominante da janela atual: ' . htmlspecialchars($dominantAsn > 0 ? ('AS' . $dominantAsn . ' â€¢ ' . $dominantDescr) : 'sem ASN ativo') . '</div>';
    $html .= '</section>';

    $html .= '<section class="flow-provider-slice">';
    $html .= '<header><strong>' . htmlspecialchars(isset($provider['local_title']) ? $provider['local_title'] : 'CDN local classificado') . '</strong><span>somente por link mais ASN/prefixo homologado</span></header>';
    $html .= '<div class="flow-provider-micro">';
    $html .= '<span>IPv4 IN local ' . htmlspecialchars($localIn4) . '</span>';
    $html .= '<span>IPv4 OUT local ' . htmlspecialchars($localOut4) . '</span>';
    if ($showv6) {
        $html .= '<span>IPv6 IN local ' . htmlspecialchars($localIn6) . '</span>';
        $html .= '<span>IPv6 OUT local ' . htmlspecialchars($localOut6) . '</span>';
    }
    $html .= '<span>Status ' . htmlspecialchars($local['status']) . '</span>';
    $html .= '</div>';
    $html .= '<div class="flow-provider-note">Links homologados: ' . htmlspecialchars($localLinks) . ' â€¢ ASN homologados: ' . htmlspecialchars($localAsns) . ' â€¢ Prefixos: ' . htmlspecialchars($localPrefixes) . '</div>';
    $html .= '<div class="flow-provider-note">' . htmlspecialchars($local['note']) . '</div>';
    $html .= '</section>';

    $html .= '<section class="flow-provider-slice">';
    $html .= '<header><strong>CDN compartilhado nÃ£o classificado</strong><span>volume presente na malha local sem assinatura segura do provedor</span></header>';
    $html .= '<div class="flow-provider-micro">';
    $html .= '<span>IPv4 IN shared ' . htmlspecialchars($sharedIn4) . '</span>';
    $html .= '<span>IPv4 OUT shared ' . htmlspecialchars($sharedOut4) . '</span>';
    if ($showv6) {
        $html .= '<span>IPv6 IN shared ' . htmlspecialchars($sharedIn6) . '</span>';
        $html .= '<span>IPv6 OUT shared ' . htmlspecialchars($sharedOut6) . '</span>';
    }
    $html .= '</div>';
    $html .= '<div class="flow-provider-note">Esse bloco representa o restante do trÃ¡fego na malha CDN local homologada que ainda nÃ£o bateu com assinatura suficiente para atribuiÃ§Ã£o segura ao provedor.</div>';
    $html .= '</section>';

    $html .= '</div>';
    $html .= '<div class="flow-graph-pair">';
    $html .= '<div class="flow-graph-card"><div class="flow-graph-label">IPv4</div>' . $graph4 . flow_render_graph_stats($stats4) . '</div>';
    if ($showv6) {
        $html .= '<div class="flow-graph-card"><div class="flow-graph-label">IPv6</div>' . $graph6 . flow_render_graph_stats($stats6) . '</div>';
    }
    $html .= '</div>';
    $html .= '</article>';

    return $html;
}

$ntop = 6;
$hours = isset($_GET['numhours']) ? (int)$_GET['numhours'] : 4;
if ($hours < 1) {
    $hours = 4;
}
$label = statsLabelForHours($hours);
$knownlinks = getknownlinks();
$selected_links = array();
$peerusage = isset($peerusage) ? $peerusage : 0;
$statsfile = $peerusage ? $daypeerstatsfile : statsFileForHours($hours);
$start = time() - $hours * 3600;
$end = time();
$providers = flow_dashboard_providers();

foreach ($knownlinks as $link) {
    if (isset($_GET["link_{$link['tag']}"])) {
        $selected_links[] = $link['tag'];
    }
}

$heroStats = array(
    array('label' => 'Janela', 'value' => $label),
    array('label' => 'Provedores', 'value' => count($providers)),
    array('label' => 'Links filtrados', 'value' => count($selected_links) ? count($selected_links) : count($knownlinks)),
    array('label' => 'Camadas', 'value' => $showv6 ? 'IPv4 + IPv6' : 'IPv4'),
);

$cards = '';
foreach ($providers as $provider) {
    $cards .= flow_render_dashboard_provider_card($provider, $hours, $start, $end, $selected_links, $peerusage, $showv6, $statsfile);
}

flow_render_shell_start('Flow | Dashboard', 'dashboard');
echo flow_render_hero(
    'traffic board',
    'Dashboard de consumo por plataforma',
    'Painel executivo focado em leitura rapida de entrega remota por ASN e CDN local classificado.',
    $heroStats
);

echo '<div class="flow-grid">';
echo '<div class="flow-stack">';
echo flow_render_panel('Controles do dashboard', flow_render_filter_form($hours, $ntop, $selected_links, 'dashboard.php'), 'fa-sliders');
echo flow_render_panel('Links monitorados', flow_render_legend_form($knownlinks, $selected_links, $hours, $ntop, 'dashboard.php'), 'fa-random');
echo flow_render_panel(
    'Regra de classificacao',
    '<div class="flow-copy-block"><p>O bloco remoto soma ASN publicos do provedor e o bloco local depende de homologacao explicita.</p><p>Arquivo esperado: <strong>' . htmlspecialchars(flow_dashboard_local_profiles_path()) . '</strong></p></div>',
    'fa-check-circle'
);
echo '</div>';
echo '<div class="flow-stack">';
echo flow_render_panel('Hyperscalers observados', '<div class="flow-provider-grid">' . $cards . '</div>', 'fa-dashboard');
echo '</div>';
echo '</div>';

flow_render_shell_end();

