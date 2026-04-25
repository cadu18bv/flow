<?php
require_once("func.inc");
require_once("flow_ui.php");

function flow_http_fetch($url) {
    $context = stream_context_create(array(
        'http' => array(
            'method' => 'GET',
            'timeout' => 12,
            'header' => implode("\r\n", array(
                'User-Agent: CECTI-Flow-Observatory/1.0',
                'Accept: text/html,application/xhtml+xml',
                'Connection: close',
            )),
        ),
    ));

    $content = @file_get_contents($url, false, $context);
    return is_string($content) ? $content : '';
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

    $entries = array();
    if (preg_match_all('#href="/exchange/([^"/?#]+)"[^>]*>([^<]+)</a>#i', $html, $matches, PREG_SET_ORDER)) {
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
    if (preg_match_all('#href="/AS([0-9]+)"#i', $html, $matches)) {
        foreach ($matches[1] as $asn) {
            $asn = (int)$asn;
            if ($asn > 0) {
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

function flow_ix_members($ixKey, $peerdb) {
    $ixKey = trim((string)$ixKey);
    if ($ixKey === '') {
        return array();
    }

    if (strpos($ixKey, 'bgphe:') === 0) {
        return flow_bgp_he_exchange_members(substr($ixKey, 6));
    }

    if (strpos($ixKey, 'pdb:') === 0 && $peerdb) {
        return $peerdb->GetIXASN((int)substr($ixKey, 4));
    }

    return array();
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

if ($my_asn) {
    $list_ix = flow_build_ix_catalog($my_asn, $peerdb);
    if (!empty($list_ix)) {
        $ixStatus = 'catalogado';
        $select_ix .= '<form method="get" class="flow-form-stack">';
        foreach ($selected_links as $tag) {
            $select_ix .= '<input type="hidden" name="link_' . htmlspecialchars($tag) . '" value="on">';
        }
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
            'Revise o parametro my_asn em config.inc e confirme se esse ASN possui presenca visivel no bgp.he.'
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
    $list_asn = flow_ix_members($ix_key, $peerdb);
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
        $ixEmptyTitle = 'bgp.he sem resposta util';
        $ixEmptyBody = 'O IX foi selecionado, mas a consulta de membros nao retornou ASN para montar o ranking. A estrutura do bgp.he pode ter mudado ou esse IX nao expoe membros publicamente.';
    }
}

$heroStats = array(
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
echo flow_render_panel('Controles de observacao', flow_render_filter_form($hours, $ntop, $selected_links, 'ix.php', array('ix' => $ix_key)), 'fa-sliders');
echo flow_render_panel('Links monitorados', flow_render_legend_form($knownlinks, $selected_links, $hours, $ntop, 'ix.php', array('ix' => $ix_key)), 'fa-random');
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
