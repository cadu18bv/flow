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

foreach ($knownlinks as $link) {
    if (isset($_GET["link_{$link['tag']}"])) {
        $selected_links[] = $link['tag'];
    }
}

if ($my_asn) {
    $list_ix = $peerdb->GetIX($my_asn);
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
}

if ($ix_id) {
    $list_asn = $peerdb->GetIXASN($ix_id);
    $topas = getasstats_top($ntop, $statsfile, $selected_links, $list_asn);
    $start = time() - $hours * 3600;
    $end = time();
    $rank = 1;
    foreach ($topas as $as => $nbytes) {
        $rows .= flow_render_as_row($rank, $as, getASInfo($as), $nbytes, $start, $end, isset($peerusage) ? $peerusage : 0, $selected_links, $showv6);
        $rank++;
    }
}

$heroStats = array(
    array('label' => 'Janela', 'value' => $label),
    array('label' => 'Top monitorado', 'value' => $ntop . ' AS'),
    array('label' => 'IX atual', 'value' => $ix_name ?: 'Não selecionado')
);

flow_render_shell_start('Flow | IX Analytics', 'ix');
echo flow_render_hero('ix analytics', 'Painel de IX', 'Visualização dedicada para ranking de ASN dentro do IX selecionado, mantendo os mesmos filtros de tráfego do radar principal.', $heroStats);

echo '<div class="flow-grid">';
echo '<div class="flow-stack">';
if ($select_ix !== '') {
    echo flow_render_panel('Meu(s) IX', $select_ix, 'fa-building');
}
echo flow_render_panel('Orquestração', flow_render_filter_form($hours, $ntop, $selected_links, 'ix.php', array('ix' => $ix_id)), 'fa-sliders');
echo flow_render_panel('Links monitorados', flow_render_legend_form($knownlinks, $selected_links, $hours, $ntop, 'ix.php', array('ix' => $ix_id)), 'fa-random');
echo '</div>';

echo '<div class="flow-stack">';
if ($rows !== '') {
    echo flow_render_panel('ASNs observados no IX', $rows, 'fa-sitemap');
} else {
    echo flow_render_panel('Nenhum IX carregado', flow_render_empty_state('Aguardando seleção', 'Escolha um IX para montar o ranking de tráfego por ASN.'), 'fa-building');
}
echo '</div>';
echo '</div>';

flow_render_shell_end();
