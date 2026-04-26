<?php
require_once("func.inc");
require_once("flow_ui.php");

function flow_http_fetch($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_USERAGENT => 'CECTI-Flow-Observatory/1.0',
            CURLOPT_HTTPHEADER => array(
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
                'Connection: close',
            ),
            CURLOPT_ENCODING => '',
        ));
        $content = curl_exec($ch);
        curl_close($ch);
        if (is_string($content) && $content !== '') {
            return $content;
        }
    }

    $context = stream_context_create(array(
        'http' => array(
            'method' => 'GET',
            'timeout' => 12,
            'header' => implode("\r\n", array(
                'User-Agent: CECTI-Flow-Observatory/1.0',
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
                'Connection: close',
            )),
        ),
    ));

    $content = @file_get_contents($url, false, $context);
    return is_string($content) ? $content : '';
}

function flow_bgp_he_state_name($code) {
    $map = array(
        'AC' => 'Rio Branco',
        'AL' => 'Maceio',
        'AM' => 'Manaus',
        'AP' => 'Macapa',
        'BA' => 'Salvador',
        'CE' => 'Fortaleza',
        'DF' => 'Brasilia',
        'ES' => 'Vitoria',
        'GO' => 'Goiania',
        'MA' => 'Sao Luis',
        'MG' => 'Belo Horizonte',
        'MS' => 'Campo Grande',
        'MT' => 'Cuiaba',
        'PA' => 'Belem',
        'PB' => 'Joao Pessoa',
        'PE' => 'Recife',
        'PI' => 'Teresina',
        'PR' => 'Curitiba',
        'RJ' => 'Rio de Janeiro',
        'RN' => 'Natal',
        'RO' => 'Porto Velho',
        'RR' => 'Boa Vista',
        'RS' => 'Porto Alegre',
        'SC' => 'Florianopolis',
        'SE' => 'Aracaju',
        'SP' => 'Sao Paulo',
        'TO' => 'Palmas',
    );

    $code = strtoupper(trim((string)$code));
    return isset($map[$code]) ? $map[$code] : $code;
}

function flow_bgp_he_exchange_entries_from_html($html) {
    $entries = array();

    if (preg_match_all('#href=[\'"](?:https?://(?:ipv4\.)?bgp\.he\.net)?/exchange/([^\'"?#<> ]+)[\'"][^>]*>(.*?)</a>#is', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $slug = trim(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'));
            $name = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES, 'UTF-8'));
            if ($slug === '' || $name === '') {
                continue;
            }
            $entries[$slug] = array(
                'id' => 'bgphe:' . $slug,
                'slug' => $slug,
                'name' => $name,
                'source' => 'bgp.he',
            );
        }
    }

    if (!empty($entries)) {
        return $entries;
    }

    if (preg_match_all('#/exchange/([A-Za-z0-9._:-]+)#', $html, $slugMatches)) {
        foreach (array_unique($slugMatches[1]) as $slug) {
            $slug = trim((string)$slug);
            if ($slug === '') {
                continue;
            }
            $name = str_replace('-', ' ', $slug);
            $entries[$slug] = array(
                'id' => 'bgphe:' . $slug,
                'slug' => $slug,
                'name' => $name,
                'source' => 'bgp.he',
            );
        }
    }

    if (!empty($entries)) {
        return $entries;
    }

    if (preg_match_all('#Exchange\s+CC\s+City\s+IPv4\s+IPv6(.*?)(?:Loading probes|Updated\s+\d|\Z)#is', $html, $sections, PREG_SET_ORDER)) {
        foreach ($sections as $section) {
            if (preg_match_all('#(?:^|\n)\s*([^\n<][^\n]+?)\s*\n\s*BR\s+([A-Za-zÀ-ÿ ]+)\s+#u', $section[1], $rows, PREG_SET_ORDER)) {
                foreach ($rows as $row) {
                    $name = trim(html_entity_decode(strip_tags($row[1]), ENT_QUOTES, 'UTF-8'));
                    $city = trim(html_entity_decode(strip_tags($row[2]), ENT_QUOTES, 'UTF-8'));
                    if ($name === '') {
                        continue;
                    }
                    $slug = preg_replace('/[^A-Za-z0-9._:-]+/', '-', $name);
                    $slug = trim((string)$slug, '-');
                    if ($slug === '') {
                        continue;
                    }
                    $entries[$slug] = array(
                        'id' => 'bgphe:' . $slug,
                        'slug' => $slug,
                        'name' => $name,
                        'source' => 'bgp.he',
                    );
                }
            }
        }
    }

    if (!empty($entries)) {
        return $entries;
    }

    if (preg_match_all('#member-of:\s+AS-PTTMetro(?:-[A-Z0-9]+)?-([A-Z]{2})#i', $html, $matches)) {
        foreach ($matches[1] as $stateCode) {
            $stateCode = strtoupper(trim((string)$stateCode));
            if ($stateCode === '') {
                continue;
            }
            $city = flow_bgp_he_state_name($stateCode);
            $slug = 'PTT-' . $city;
            $entries[$slug] = array(
                'id' => 'bgphe:' . $slug,
                'slug' => $slug,
                'name' => 'PTT ' . $city,
                'source' => 'bgp.he',
            );
        }
    }

    return $entries;
}

function flow_resolve_ix_query_asn() {
    global $my_asn;

    $queryAsn = isset($_GET['asn']) ? trim((string)$_GET['asn']) : '';
    if ($queryAsn !== '' && ctype_digit($queryAsn)) {
        return (int)$queryAsn;
    }

    if (isset($my_asn) && preg_match('/^[0-9]+$/', (string)$my_asn)) {
        return (int)$my_asn;
    }

    return 0;
}

function flow_bgp_he_exchange_catalog($asn) {
    $asn = (int)$asn;
    if ($asn <= 0) {
        return array();
    }

    $html = flow_http_fetch('https://ipv4.bgp.he.net/AS' . $asn);
    if ($html === '') {
        $html = flow_http_fetch('https://bgp.he.net/AS' . $asn);
    }
    if ($html === '') {
        return array();
    }

    $entries = flow_bgp_he_exchange_entries_from_html($html);
    return array_values($entries);
}

function flow_bgp_he_exchange_members($slug) {
    $slug = trim((string)$slug);
    if ($slug === '') {
        return array();
    }

    $html = flow_http_fetch('https://bgp.he.net/exchange/' . rawurlencode($slug));
    if ($html === '') {
        return array();
    }

    $members = array();
    if (preg_match_all('#href=[\'"](?:https?://(?:ipv4\.)?bgp\.he\.net)?/AS([0-9]+)[\'"]#i', $html, $matches)) {
        foreach ($matches[1] as $asn) {
            $asn = (int)$asn;
            if ($asn > 0) {
                $members[$asn] = $asn;
            }
        }
    }

    return array_values($members);
}

function flow_ix_normalize_text($text) {
    $text = trim((string)$text);
    if ($text === '') {
        return '';
    }
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($ascii !== false) {
        $text = $ascii;
    }
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
    return trim((string)$text);
}

function flow_ixbr_slug_catalog() {
    $html = flow_http_fetch('https://ix.br/particip');
    if ($html === '') {
        return array();
    }

    $catalog = array();
    if (preg_match_all('#<option[^>]*value=["\']?([^"\'>\s]+)["\']?[^>]*>(.*?)</option>#is', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $raw = trim(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'));
            $label = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES, 'UTF-8'));
            if ($raw === '' || $label === '' || strtolower($raw) === 'select') {
                continue;
            }
            $raw = trim($raw, '/');
            $parts = explode('/', $raw);
            $slug = strtolower(trim((string)end($parts)));
            if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
                continue;
            }
            $catalog[$slug] = array(
                'slug' => $slug,
                'label' => $label,
                'normalized' => flow_ix_normalize_text($label),
            );
        }
    }

    return $catalog;
}

function flow_ix_guess_slug_from_name($ixName, $catalog) {
    $normalizedName = flow_ix_normalize_text($ixName);
    if ($normalizedName === '' || empty($catalog)) {
        return '';
    }

    foreach ($catalog as $entry) {
        if ($entry['normalized'] !== '' && strpos($normalizedName, $entry['normalized']) !== false) {
            return $entry['slug'];
        }
    }

    $cityMap = array(
        'rio de janeiro' => 'rj',
        'sao paulo' => 'sp',
        'fortaleza' => 'ce',
        'recife' => 'pe',
        'porto alegre' => 'rs',
        'curitiba' => 'pr',
        'brasilia' => 'df',
        'goiania' => 'go',
        'belo horizonte' => 'mg',
        'salvador' => 'ba',
        'belem' => 'pa',
        'manaus' => 'am',
        'vitoria' => 'vix',
    );

    foreach ($cityMap as $city => $slug) {
        if (strpos($normalizedName, $city) !== false) {
            return isset($catalog[$slug]) ? $slug : '';
        }
    }

    if (preg_match('/\b([a-z]{2,3})\b/', $normalizedName, $m)) {
        $candidate = strtolower($m[1]);
        if (isset($catalog[$candidate])) {
            return $candidate;
        }
    }

    return '';
}

function flow_ixbr_exchange_members_by_name($ixName, &$resolvedLabel = '') {
    $resolvedLabel = '';
    $catalog = flow_ixbr_slug_catalog();
    if (empty($catalog)) {
        return array();
    }

    $slug = flow_ix_guess_slug_from_name($ixName, $catalog);
    if ($slug === '') {
        return array();
    }

    $resolvedLabel = isset($catalog[$slug]) ? $catalog[$slug]['label'] : strtoupper($slug);
    $html = flow_http_fetch('https://ix.br/particip/' . rawurlencode($slug));
    if ($html === '') {
        return array();
    }

    $members = array();
    if (preg_match_all('/\bAS\s*([1-9][0-9]{1,9})\b/i', $html, $matches)) {
        foreach ($matches[1] as $asnRaw) {
            $asn = (int)$asnRaw;
            if ($asn > 0 && $asn < 4294967295) {
                $members[$asn] = $asn;
            }
        }
    }
    if (!empty($members)) {
        return array_values($members);
    }

    if (preg_match_all('/(?:^|[^0-9])([1-9][0-9]{1,9})(?:[^0-9]|$)/m', strip_tags($html), $matches)) {
        foreach ($matches[1] as $asnRaw) {
            $asn = (int)$asnRaw;
            if ($asn >= 32 && $asn <= 4294967294) {
                $members[$asn] = $asn;
            }
        }
    }

    return array_values($members);
}

function flow_build_ix_catalog($myAsn, $peerdb) {
    $catalog = array();

    $bgpHeEntries = flow_bgp_he_exchange_catalog($myAsn);
    foreach ($bgpHeEntries as $entry) {
        $catalog[$entry['id']] = $entry;
    }

    if ($peerdb) {
        $peerdbEntries = $peerdb->GetIX($myAsn);
        if (!empty($peerdbEntries)) {
            foreach ($peerdbEntries as $entry) {
                $id = 'pdb:' . (int)$entry->ix_id;
                if (!isset($catalog[$id])) {
                    $catalog[$id] = array(
                        'id' => $id,
                        'slug' => (string)$entry->ix_id,
                        'name' => $entry->name,
                        'source' => 'PeeringDB',
                    );
                }
            }
        }
    }

    uasort($catalog, function ($left, $right) {
        return strcasecmp($left['name'], $right['name']);
    });

    return array_values($catalog);
}

function flow_ix_members($ixKey, $peerdb, $ixName = '') {
    $ixKey = trim((string)$ixKey);
    if ($ixKey === '') {
        return array(array(), 'indefinido');
    }

    if (strpos($ixKey, 'bgphe:') === 0) {
        $members = flow_bgp_he_exchange_members(substr($ixKey, 6));
        if (!empty($members)) {
            return array($members, 'bgp.he');
        }

        $ixbrLabel = '';
        $ixbrMembers = flow_ixbr_exchange_members_by_name($ixName, $ixbrLabel);
        if (!empty($ixbrMembers)) {
            return array($ixbrMembers, 'ix.br/particip' . ($ixbrLabel !== '' ? ' (' . $ixbrLabel . ')' : ''));
        }
        return array(array(), 'bgp.he (sem membros)');
    }

    if (strpos($ixKey, 'pdb:') === 0 && $peerdb) {
        $members = $peerdb->GetIXASN((int)substr($ixKey, 4));
        if (!empty($members)) {
            return array($members, 'PeeringDB');
        }
        return array(array(), 'PeeringDB (sem membros)');
    }

    return array(array(), 'desconhecida');
}

function flow_ix_name_from_catalog($catalog, $ixKey) {
    foreach ($catalog as $entry) {
        if ($entry['id'] === $ixKey) {
            return $entry['name'];
        }
    }
    return '';
}

$selected_links = array();
$ntop = isset($_GET['n']) ? (int)$_GET['n'] : 20;
if ($ntop < 1) $ntop = 20;
if ($ntop > 200) $ntop = 200;
$hours = isset($_GET['numhours']) ? (int)$_GET['numhours'] : 24;
$ix_key = isset($_GET['ix']) ? trim((string)$_GET['ix']) : '';
$statsfile = isset($peerusage) && $peerusage ? $daypeerstatsfile : statsFileForHours($hours);
$label = statsLabelForHours($hours);
$knownlinks = getknownlinks();
$peerdb = class_exists('PeeringDB') ? new PeeringDB() : null;
$query_asn = flow_resolve_ix_query_asn();
$select_ix = '';
$rows = '';
$ix_name = '';
$ixStatus = 'nao-configurado';
$ixPanelTitle = 'Nenhum IX carregado';
$ixEmptyTitle = 'Aguardando selecao';
$ixEmptyBody = 'Escolha um IX para montar o ranking de trafego por ASN.';
$ixSource = 'bgp.he';

foreach ($knownlinks as $link) {
    if (isset($_GET["link_{$link['tag']}"])) {
        $selected_links[] = $link['tag'];
    }
}

if ($query_asn) {
    $list_ix = flow_build_ix_catalog($query_asn, $peerdb);
    if (!empty($list_ix)) {
        if ($ix_key === '') {
            $ix_key = $list_ix[0]['id'];
        }
        $ixStatus = 'catalogado';
        $select_ix .= '<form method="get" class="flow-form-stack">';
        foreach ($selected_links as $tag) {
            $select_ix .= '<input type="hidden" name="link_' . htmlspecialchars($tag) . '" value="on">';
        }
        $select_ix .= '<label>ASN consultado</label>';
        $select_ix .= '<input class="flow-input" type="text" name="asn" value="' . htmlspecialchars((string)$query_asn) . '" placeholder="Ex.: 268809">';
        $select_ix .= '<input type="hidden" name="n" value="' . (int)$ntop . '">';
        $select_ix .= '<input type="hidden" name="numhours" value="' . (int)$hours . '">';
        $select_ix .= '<label>Selecione o IX</label>';
        $select_ix .= '<select class="flow-input" name="ix">';
        $select_ix .= '<option value="">Escolha um IX</option>';
        foreach ($list_ix as $entry) {
            $selected = $entry['id'] === $ix_key ? ' selected' : '';
            if ($entry['id'] === $ix_key) {
                $ix_name = $entry['name'];
                $ixSource = $entry['source'];
            }
            $select_ix .= '<option value="' . htmlspecialchars($entry['id']) . '"' . $selected . '>' . htmlspecialchars($entry['name'] . ' [' . $entry['source'] . ']') . '</option>';
        }
        $select_ix .= '</select>';
        $select_ix .= '<button class="flow-button" type="submit">Carregar IX</button>';
        $select_ix .= '<span class="flow-search-hint">Catalogo priorizado por bgp.he com complemento de PeeringDB quando disponivel.</span>';
        $select_ix .= '</form>';
    } else {
        $ixStatus = 'sem-ix';
        $select_ix = flow_render_empty_state(
            'Nenhum IX encontrado para o ASN local',
            'Revise o ASN consultado e confirme se esse sistema autonomo possui presenca visivel no bgp.he.'
        );
        $ixPanelTitle = 'ASN sem IX vinculado';
        $ixEmptyTitle = 'Sem IX para o ASN informado';
        $ixEmptyBody = 'A consulta de IX depende do ASN local configurado e da visibilidade desse ASN no bgp.he.';
    }
} else {
    $select_ix = flow_render_empty_state(
        'ASN local nao configurado',
        'Defina o my_asn em config.inc para habilitar a descoberta automatica de IX.'
    );
    $ixPanelTitle = 'ASN local ausente';
    $ixEmptyTitle = 'Configuracao incompleta';
    $ixEmptyBody = 'Sem o my_asn configurado, a plataforma nao consegue listar os IX disponiveis.';
}

if ($ix_key !== '') {
    list($list_asn, $membersSource) = flow_ix_members($ix_key, $peerdb, $ix_name);
    if (!empty($membersSource) && strpos($membersSource, 'sem membros') === false) {
        $ixSource = $membersSource;
    }
    if (!empty($list_asn)) {
        $topas = getasstats_top($ntop, $statsfile, $selected_links, $list_asn);
        $start = time() - $hours * 3600;
        $end = time();
        $rank = 1;
        foreach ($topas as $as => $nbytes) {
            $rows .= flow_render_as_row($rank, $as, getASInfo($as), $nbytes, $start, $end, isset($peerusage) ? $peerusage : 0, $selected_links, $showv6);
            $rank++;
        }

        if ($rows === '') {
            $ixPanelTitle = 'IX selecionado sem trafego visivel';
            $ixEmptyTitle = 'Sem amostras para o IX';
            $ixEmptyBody = 'O IX foi encontrado, mas nao houve correspondencia com a janela, os links filtrados ou a base atual.';
        }
    } else {
        $ixPanelTitle = 'IX sem membros retornados';
        $ixEmptyTitle = 'Fonte sem resposta util';
        $ixEmptyBody = 'O IX foi selecionado, mas a consulta de membros nao retornou ASN para montar o ranking. Fontes testadas: bgp.he e fallback ix.br/particip.';
    }
}

$heroStats = array(
    array('label' => 'ASN consultado', 'value' => $query_asn ? ('AS' . $query_asn) : 'nao definido'),
    array('label' => 'Janela', 'value' => $label),
    array('label' => 'Top monitorado', 'value' => $ntop . ' AS'),
    array('label' => 'IX atual', 'value' => $ix_name ?: 'Nao selecionado'),
    array('label' => 'Fonte', 'value' => $ixSource)
);

flow_render_shell_start('Flow | IX Analytics', 'ix');
echo flow_render_hero('ix analytics', 'Painel de IX', 'Visualizacao dedicada para ranking de ASN dentro do IX selecionado, usando catalogo e membros priorizados por bgp.he.', $heroStats);

echo '<div class="flow-grid">';
echo '<div class="flow-stack">';
echo flow_render_panel('Meu(s) IX', $select_ix, 'fa-building');
echo flow_render_panel('Controles de observacao', flow_render_filter_form($hours, $ntop, $selected_links, 'ix.php', array('ix' => $ix_key, 'asn' => $query_asn)), 'fa-sliders');
echo flow_render_panel('Links monitorados', flow_render_legend_form($knownlinks, $selected_links, $hours, $ntop, 'ix.php', array('ix' => $ix_key, 'asn' => $query_asn)), 'fa-random');
echo '</div>';

echo '<div class="flow-stack">';
if ($rows !== '') {
    echo flow_render_panel('ASNs observados no IX', $rows, 'fa-sitemap');
} else {
    echo flow_render_panel($ixPanelTitle, flow_render_empty_state($ixEmptyTitle, $ixEmptyBody), 'fa-building');
}
echo '</div>';
echo '</div>';

flow_render_shell_end();
