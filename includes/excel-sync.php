<?php
/**
 * Lógica de sincronização com o Excel (Via Script Customizado do Usuário)
 */

if (!defined('ABSPATH')) {
    exit;
}

// 1. Agendar o Cron (5 minutos) - Usando nomes únicos para evitar conflito com Code Snippets
add_action('init', function() {
    if (!wp_next_scheduled('rene_v2_excel_sync_cron')) {
        wp_schedule_event(time(), 'rene_v2_five_minutes', 'rene_v2_excel_sync_cron');
    }
});

add_filter('cron_schedules', function($schedules) {
    if (!isset($schedules['rene_v2_five_minutes'])) {
        $schedules['rene_v2_five_minutes'] = array('interval' => 300, 'display' => 'A cada 5 minutos (FormSync v2)');
    }
    return $schedules;
});

add_action('rene_v2_excel_sync_cron', 'rene_v2_run_excel_sync');

/**
 * Função principal disparada pelo Cron
 */
function rene_v2_run_excel_sync() {
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
            $slug = get_post_meta($post_id, 'page_slug', true); 
            $answers_json = get_post_meta($post_id, 'survey_answers', true);
            $answers = json_decode($answers_json, true);

            // Chama o handler de exportação
            $sync_result = rene_v2_custom_excel_handler($post_id, $slug, $answers);

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
 * Lógica adaptada do seu Snippet original para o novo formato do Plugin
 */
function rene_v2_custom_excel_handler($post_id, $slug, $answers) {
    // 1. Busca a configuração da pesquisa para pegar a URL da planilha
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

    // 2. Prepara os dados para o Google Sheets (Estilo GET do seu Snippet)
    $raw_title = get_the_title($post_id);
    preg_match('/#(\d+)$/', $raw_title, $matches);
    $titulo = isset($matches[1]) ? '#' . $matches[1] : $raw_title;

    $mapa_dados = [
        'post_title' => $titulo,
        'timestamp'  => current_time('mysql'),
        'slug'       => $slug,
    ];

    // Mapeia respostas conforme a ORDEM das questões na configuração (para bater com o loop do Excel)
    $questions_json = get_post_meta($surveys[0]->ID, 'questions_data', true) ?: '[]';
    $questions = json_decode($questions_json, true);

    if (is_array($questions) && is_array($answers)) {
        $idx = 4; // Começa no 4 como no seu script
        foreach ($questions as $q) {
            $qid = isset($q['id']) ? $q['id'] : '';
            if (empty($qid)) continue;

            $val = isset($answers[$qid]) ? $answers[$qid] : '';
            $mapa_dados['pergunta_' . $idx] = $val;
            $idx++;
        }
    }

    // Backup: Se houver respostas que não batem com a config atual, manda com ID original
    if (is_array($answers)) {
        foreach ($answers as $qid => $val) {
            $key = 'q_' . $qid;
            if (!isset($mapa_dados[$key])) {
                // $mapa_dados[$key] = $val; // Opcional: descomente se quiser enviar o ID bruto também
            }
        }
    }

    // 3. Envia via GET (wp_remote_get)
    $url_final = add_query_arg(array_map('urlencode', $mapa_dados), $endpoint);
    $response = wp_remote_get($url_final, ['timeout' => 30]);

    if (is_wp_error($response)) return false;

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    // Critério de sucesso do seu Snippet original
    return ($code == 200 && strpos(strtolower($body), 'sucesso') !== false);
}
