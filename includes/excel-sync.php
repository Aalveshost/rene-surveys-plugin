<?php
/**
 * Lógica de sincronização com o Excel (Via Script Customizado do Usuário)
 */

if (!defined('ABSPATH')) {
    exit;
}

// 1. Agendar o Cron (De hora em hora, como sugerido)
if (!wp_next_scheduled('rene_survey_excel_sync_cron')) {
    wp_schedule_event(time(), 'hourly', 'rene_survey_excel_sync_cron');
}

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
    
    // --- COLE SEU SCRIPT ABAIXO ---
    // Exemplo: error_log("Sincronizando Post $post_id para a planilha...");
    
    // Você pode usar cURL para enviar para uma URL ou salvar um arquivo .csv / .xlsx
    
    // Simulação de sucesso
    return true; 
}
