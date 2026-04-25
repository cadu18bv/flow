<?php
require_once("func.inc");
require_once("flow_ui.php");

$selected_links = array();
$ntop = isset($_GET['n']) ? (int)$_GET['n'] : 20;
if ($ntop < 1) $ntop = 20;
if ($ntop > 200) $ntop = 200;
$hours = isset($_GET['numhours']) ? (int)$_GET['numhours'] : 24;
$ix_id = isset($_GET['ix']) ? (int)$_GET['ix'] : 0;
$statsfile = isset($peerusage) && $peerusage ? $daypeerstatsfile : statsFileForHours($hours);
$label = statsLabelForHours($hours);
$knownlinks = getknownlinks();
$peerdb = new PeeringDB();
$select_ix = '';
$rows = '';
$ix_name = '';
$ixStatus = 'nao-configurado';
$ixPanelTitle = 'Nenhum IX carregado';
$ixEmptyTitle = 'Aguardando selecao';
$ixEmptyBody = 'Escolha um IX para montar o ranking de trafego por ASN.';

foreach ($knownlinks as $link) {
    if (isset($_GET["link_{$link['tag']}"])) {
        $selected_links[] = $link['tag'];
    }
}

if ($my_asn) {
    $list_ix = $peerdb->GetIX($my_asn);
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
            $selected = $entry->ix_id == $ix_id ? ' selected' : '';
            if ($entry->ix_id == $ix_id) {
                $ix_name = $entry->name;
            }
            $select_ix .= '<option value="' . (int)$entry->ix_id . '"' . $selected . '>' . htmlspecialchars($entry->name) . '</option>';
        }
        $select_ix .= '</select>';
        $select_ix .= '<button class="flow-button" type="submit">Carregar IX</button>';
        $select_ix .= '</form>';
    } else {
        $ixStatus = 'sem-ix';
        $select_ix = flow_render_empty_state(
            'Nenhum IX encontrado para o ASN local',
            'Revise o parametro my_asn em config.inc e confirme se esse ASN possui presenca cadastrada no PeeringDB.'
        );
        $ixPanelTitle = 'ASN sem IX vinculado';
        $ixEmptyTitle = 'Sem IX para o ASN informado';
        $ixEmptyBody = 'A consulta de IX depende do ASN local configurado e do retorno do PeeringDB.';
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

if ($ix_id) {
    $list_asn = $peerdb->GetIXASN($ix_id);
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
        $ixEmptyTitle = 'PeeringDB sem resposta util';
        $ixEmptyBody = 'O IX foi selecionado, mas a consulta de membros nao retornou ASN para montar o ranking.';
    }
}

$heroStats = array(
    array('label' => 'Janela', 'value' => $label),
    array('label' => 'Top monitorado', 'value' => $ntop . ' AS'),
    array('label' => 'IX atual', 'value' => $ix_name ?: 'Nao selecionado'),
    array('label' => 'Status', 'value' => $ixStatus)
);

flow_render_shell_start('Flow | IX Analytics', 'ix');
echo flow_render_hero('ix analytics', 'Painel de IX', 'Visualizacao dedicada para ranking de ASN dentro do IX selecionado, mantendo os mesmos filtros de trafego do radar principal.', $heroStats);

echo '<div class="flow-grid">';
echo '<div class="flow-stack">';
echo flow_render_panel('Meu(s) IX', $select_ix, 'fa-building');
echo flow_render_panel('Controles de observacao', flow_render_filter_form($hours, $ntop, $selected_links, 'ix.php', array('ix' => $ix_id)), 'fa-sliders');
echo flow_render_panel('Links monitorados', flow_render_legend_form($knownlinks, $selected_links, $hours, $ntop, 'ix.php', array('ix' => $ix_id)), 'fa-random');
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
