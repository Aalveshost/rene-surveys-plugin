<?php
/**
 * Lógica de sincronização com o Excel (Dinamica, Alternável e com Logs)
 * Versão 1.3.5 - Refinamento de mapeamento e throttling
 */

if (!defined('ABSPATH')) {
    exit;
}

// 1. Agendar o Cron com base nas configurações
add_action('init', 'fswp_v2_reschedule_cron');

function fswp_v2_reschedule_cron() {
    $interval_sec = fswp_v2_get_shortest_interval();
    $hook = 'rene_v2_excel_sync_cron';

    $current_interval = get_option('fswp_current_sync_interval', 300);

    if ($interval_sec != $current_interval || !wp_next_scheduled($hook)) {
        wp_clear_scheduled_hook($hook);
        wp_schedule_event(time(), 'fswp_dynamic_interval', $hook);
        update_option('fswp_current_sync_interval', $interval_sec);
    }
}

add_filter('cron_schedules', function($schedules) {
    $interval_sec = get_option('fswp_current_sync_interval', 300);
    $schedules['fswp_dynamic_interval'] = array(
        'interval' => $interval_sec,
        'display'  => 'Intervalo Dinâmico FormSync (' . $interval_sec . 's)'
    );
    return $schedules;
});

/**
 * Pega o menor intervalo configurado entre todas as pesquisas ATIVAS
 */
function fswp_v2_get_shortest_interval() {
    $surveys = get_posts(['post_type' => 'questionarios', 'posts_per_page' => -1]);
    $min_sec = 300; 

    foreach ($surveys as $s) {
        $config_json = get_post_meta($s->ID, 'survey_config', true) ?: '{}';
        $config = json_decode($config_json, true);
        
        $is_enabled = isset($config['sync_enabled']) ? $config['sync_enabled'] : true;
        
        if ($is_enabled && !empty($config['spreadsheet_url']) && !empty($config['sync_interval'])) {
            $val = intval($config['sync_interval']);
            $unit = isset($config['sync_unit']) ? $config['sync_unit'] : 'minutes';
            
            $sec = $val;
            if ($unit === 'minutes') $sec *= 60;
            if ($unit === 'hours') $sec *= 3600;
            
            if ($sec > 0 && $sec < $min_sec) $min_sec = $sec;
        }
    }
    return $min_sec;
}

add_action('rene_v2_excel_sync_cron', 'rene_v2_run_excel_sync');

/**
 * Função principal disparada pelo Cron
 */
function rene_v2_run_excel_sync() {
    $args = array(
        'post_type'      => 'respostas_survey',
        'post_status'    => 'publish',
        'posts_per_page' => 25, // Reduzido para maior estabilidade por ciclo
        'meta_query'     => array(
            'relation' => 'OR',
            array('key' => 'excel_sync_status', 'compare' => 'NOT EXISTS'),
            array('key' => 'excel_sync_status', 'value' => 'pending', 'compare' => '='),
            array('key' => 'excel_sync_status', 'value' => 'failed', 'compare' => '='),
        ),
    );

    $query = new WP_Query($args);

    if ($query->have_posts()) {
        $count = $query->post_count;
        fswp_add_log("Iniciando varredura. Encontrados: $count pendentes.", 'info');

        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $slug = get_post_meta($post_id, 'page_slug', true); 
            $answers_json = get_post_meta($post_id, 'survey_answers', true);
            $answers = json_decode($answers_json, true);

            $sync_result = rene_v2_custom_excel_handler($post_id, $slug, $answers);

            if ($sync_result === true) {
                update_post_meta($post_id, 'excel_sync_status', 'synced');
                update_post_meta($post_id, 'excel_sync_date', current_time('mysql'));
            } elseif ($sync_result === false) {
                update_post_meta($post_id, 'excel_sync_status', 'failed');
            }
            
            // Throttling: Pequeno delay entre envios para não sobrecarregar
            usleep(300000); // 0.3 segundos
        }
        wp_reset_postdata();
    }
}

/**
 * Handler de exportação
 */
function rene_v2_custom_excel_handler($post_id, $slug, $answers) {
    $surveys = get_posts([
        'post_type' => 'questionarios',
        'meta_key' => 'page_slug',
        'meta_value' => $slug,
        'posts_per_page' => 1
    ]);
    if (!$surveys) return null;
    
    $config_json = get_post_meta($surveys[0]->ID, 'survey_config', true) ?: '{}';
    $config = json_decode($config_json, true);
    
    $is_enabled = isset($config['sync_enabled']) ? $config['sync_enabled'] : true;
    if (!$is_enabled) return null;

    $endpoint = isset($config['spreadsheet_url']) ? $config['spreadsheet_url'] : '';
    if (empty($endpoint)) return null;

    $raw_title = get_the_title($post_id);
    preg_match('/#(\d+)$/', $raw_title, $matches);
    $titulo = isset($matches[1]) ? '#' . $matches[1] : $raw_title;

    // Limpa o nome da empresa
    $empresa_nome = str_replace('Questionário: ', '', $surveys[0]->post_title);

    $mapa_dados = [
        'post_title' => $titulo,
        'timestamp'  => current_time('mysql'),
        'empresa'    => $empresa_nome,
    ];

    $questions_json = get_post_meta($surveys[0]->ID, 'questions_data', true) ?: '[]';
    $questions = json_decode($questions_json, true);

    if (is_array($questions) && is_array($answers)) {
        $idx = 4;
        foreach ($questions as $q) {
            $type = isset($q['type']) ? $q['type'] : '';
            
            // IGNORA BLOCOS QUE NÃO SÃO PERGUNTAS (Títulos e Quebras)
            if ($type === 'section_title' || $type === 'page_break') {
                continue; 
            }

            $qid = isset($q['id']) ? $q['id'] : '';
            if (empty($qid)) continue;

            $val = isset($answers[$qid]) ? $answers[$qid] : '';
            $mapa_dados['pergunta_' . $idx] = $val;
            $idx++;
        }
    }

    $url_final = add_query_arg(array_map('urlencode', $mapa_dados), $endpoint);
    $response = wp_remote_get($url_final, ['timeout' => 45]); // Timeout maior para segurança

    if (is_wp_error($response)) {
        fswp_add_log("Erro conexão (#$post_id): " . $response->get_error_message(), 'failed');
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($code == 200 && strpos(strtolower($body), 'sucesso') !== false) {
        return true;
    } else {
        fswp_add_log("Erro Google (#$post_id): Código $code. Body snippet: " . substr($body, 0, 80), 'failed');
        return false;
    }
}
