<?php
/**
 * Lógica de sincronização com o Excel (Via Script Customizado do Usuário)
 */

if (!defined('ABSPATH')) {
    exit;
}

function rene_register_sync_cron() {
    if (!wp_next_scheduled('rene_survey_excel_sync_cron')) {
        wp_schedule_event(time(), 'five_minutes', 'rene_survey_excel_sync_cron');
    }
}
add_action('wp', 'rene_register_sync_cron');

add_filter('cron_schedules', function($schedules) {
    if (!isset($schedules['five_minutes'])) {
        $schedules['five_minutes'] = array('interval' => 300, 'display' => 'A cada 5 minutos');
    }
    return $schedules;
});

add_action('rene_survey_excel_sync_cron', 'rene_run_excel_sync');

/**
 * Função principal disparada pelo Cron
 */
function rene_run_excel_sync() {
    // Busca posts do CPT "respostas_survey" que ainda não foram sincronizados
    $args = array(
        'post_type'      => 'respostas_survey',
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'meta_query'     => array(
            'relation' => 'OR',
            array(
                'key'     => 'excel_sync_status',
                'compare' => 'NOT EXISTS',
            ),
            array(
                'key'     => 'excel_sync_status',
                'value'   => 'pending',
                'compare' => '=',
            ),
        ),
    );

    $query = new WP_Query($args);

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            
            // Pega os dados
            $slug = get_post_meta($post_id, 'page_slug', true); // O slug da página
            $answers_json = get_post_meta($post_id, 'survey_answers', true);
            $answers = json_decode($answers_json, true);

            // Chama a função de integração do usuário
            $sync_result = rene_custom_excel_export_handler($post_id, $slug, $answers);

            if ($sync_result) {
                update_post_meta($post_id, 'excel_sync_status', 'synced');
                update_post_meta($post_id, 'excel_sync_date', current_time('mysql'));
            } else {
                update_post_meta($post_id, 'excel_sync_status', 'failed');
            }
        }
        wp_reset_postdata();
    }
}

/**
 * ESPAÇO PARA O USUÁRIO: INSIRA SEU SCRIPT DE EXCEL AQUI
 * 
 * Esta função deve retornar true em caso de sucesso no envio para a planilha.
 */
function rene_custom_excel_export_handler($post_id, $slug, $answers) {
    // 1. Busca a configuração da pesquisa para pegar a URL da planilha
    $survey_id = 0;
    $surveys = get_posts([
        'post_type' => 'questionarios',
        'meta_key' => 'page_slug',
        'meta_value' => $slug,
        'posts_per_page' => 1
    ]);
    if (!$surveys) return false;
    
    $config_json = get_post_meta($surveys[0]->ID, 'survey_config', true) ?: '{}';
    $config = json_decode($config_json, true);
    $endpoint = isset($config['spreadsheet_url']) ? $config['spreadsheet_url'] : '';
    
    if (empty($endpoint)) return false;

    // 2. Prepara os dados para o Google Sheets
    // Pega o título/ID da resposta
    $raw_title = get_the_title($post_id);
    preg_match('/#(\d+)$/', $raw_title, $matches);
    $titulo = isset($matches[1]) ? '#' . $matches[1] : $raw_title;

    $mapa_dados = [
        'post_title' => $titulo,
        'timestamp'  => current_time('mysql'),
        'slug'       => $slug,
    ];

    // Mapeia todas as respostas
    if (is_array($answers)) {
        foreach ($answers as $qid => $val) {
            $mapa_dados['q_' . $qid] = $val;
        }
    }

    // 3. Envia via GET (como no seu script original)
    $url_final = add_query_arg(array_map('urlencode', $mapa_dados), $endpoint);
    $response = wp_remote_get($url_final, ['timeout' => 30]);

    if (is_wp_error($response)) return false;

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    // Verifica sucesso (200 + palavra "sucesso" no corpo, como no seu script)
    return ($code == 200 && strpos(strtolower($body), 'sucesso') !== false);
}
