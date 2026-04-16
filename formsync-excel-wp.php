<?php
/**
 * Plugin Name: FormSync Excel WP
 * Description: Sistema dinâmico de pesquisas de segurança do trabalho com sincronização para Excel. No Elementor, arraste o widget <strong>FormSync Excel WP</strong>. Em outros construtores, use o shortcode <strong>[render_survey page_slug="slug-da-pagina"]</strong>.
 * Version: 1.3.1
 * Author: Alef Alves
 * Author URI: https://aalves.dev
 * Text Domain: formsync-excel-wp
 */

if (!defined('ABSPATH')) {
    exit;
}

// 0. Incluir componentes
require_once plugin_dir_path(__FILE__) . 'includes/excel-sync.php';

// 0.1 Registrar Widget Elementor (se o Elementor estiver ativo)
add_action('elementor/widgets/register', function($widgets_manager) {
    require_once plugin_dir_path(__FILE__) . 'includes/elementor-widget.php';
    $widgets_manager->register(new FormSync_Elementor_Widget());
});

define('FSWP_VER', '1.3.0');

// 1. Enfileirar Scripts e Estilos para o Front-end
add_action('wp_enqueue_scripts', 'rene_surveys_enqueue_scripts');
function rene_surveys_enqueue_scripts() {
    // Carrega sempre no front-end (Elementor não expe has_shortcode no post_content)
    wp_enqueue_style('rene-surveys-style', plugin_dir_url(__FILE__) . 'public/style.css', array(), FSWP_VER);
    wp_enqueue_script('rene-surveys-script', plugin_dir_url(__FILE__) . 'public/script.js', array('jquery'), FSWP_VER, true);

    // Passar dados do PHP para o JS
    wp_localize_script('rene-surveys-script', 'ReneSurveysData', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('rene_survey_nonce')
    ));
}

// 1.1 Enfileirar Scripts para o Admin (Builder)
add_action('admin_enqueue_scripts', 'rene_surveys_admin_scripts');
function rene_surveys_admin_scripts($hook) {
    if ('toplevel_page_rene-survey-builder' !== $hook) {
        return;
    }
    wp_enqueue_script('rene-builder-script', plugin_dir_url(__FILE__) . 'admin/builder.js', array('jquery'), FSWP_VER, true);
    wp_localize_script('rene-builder-script', 'ReneBuilderData', array(
        'nonce' => wp_create_nonce('rene_builder_nonce')
    ));
}

// 1.2 Menu de Admin
add_action('admin_menu', 'rene_surveys_menu');
function rene_surveys_menu() {
    add_menu_page(
        'Survey Builder',
        'Survey Builder',
        'manage_options',
        'rene-survey-builder',
        'rene_surveys_render_builder',
        'dashicons-clipboard',
        30
    );
}

// 1.3 Registro de CPTs e Logs
add_action('init', function() {
    register_post_type('fswp_log', [
        'public' => false,
        'show_ui' => false,
        'supports' => ['title', 'editor']
    ]);
});

add_action('admin_menu', function() {
    add_submenu_page(
        'edit.php?post_type=questionarios',
        'Logs de Sincronização',
        'Logs (Sync)',
        'manage_options',
        'fswp-logs',
        'fswp_render_logs_page'
    );
});

function fswp_render_logs_page() {
    fswp_cleanup_old_logs();
    $logs = get_posts(['post_type' => 'fswp_log', 'posts_per_page' => 100, 'orderby' => 'date', 'order' => 'DESC']);
    ?>
    <div class="wrap" style="background:#1a1a1e; padding: 25px; border-radius: 12px; color: #fff; margin-top:20px; font-family: 'Inter', sans-serif;">
        <h1 style="color:#8257e5; margin-bottom:20px; font-weight: 700;">📋 Logs de Sincronização</h1>
        <p style="color:#7a88a8; margin-bottom:20px;">Exibindo os últimos registros (retenção de 15 dias).</p>
        <table class="widefat" style="background:#121214; border:1px solid #323238; border-collapse: collapse; width:100%; color:#e1e1e6;">
            <thead>
                <tr style="background:#2d2d35; text-align: left;">
                    <th style="padding:15px; border:1px solid #323238;">Data/Hora</th>
                    <th style="padding:15px; border:1px solid #323238;">Mensagem</th>
                    <th style="padding:15px; border:1px solid #323238;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if($logs): foreach($logs as $log): 
                    $status = get_post_meta($log->ID, '_log_status', true);
                    $color = ($status == 'success') ? '#4caf50' : (($status == 'failed') ? '#f44336' : '#ff9800');
                ?>
                <tr style="border-bottom:1px solid #323238;">
                    <td style="padding:12px; opacity:0.7;"><?php echo get_the_date('d/m H:i:s', $log->ID); ?></td>
                    <td style="padding:12px;"><?php echo esc_html($log->post_content); ?></td>
                    <td style="padding:12px;"><span style="background:<?php echo $color; ?>22; color:<?php echo $color; ?>; padding:4px 10px; border-radius:4px; font-size:0.75rem; font-weight:700;"><?php echo strtoupper($status); ?></span></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="3" style="padding:40px; text-align:center; color:#7a88a8;">Nenhum log encontrado.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function fswp_add_log($message, $status = 'info') {
    $log_id = wp_insert_post(['post_type' => 'fswp_log', 'post_title' => 'Log', 'post_content' => $message, 'post_status' => 'publish']);
    update_post_meta($log_id, '_log_status', $status);
}

function fswp_cleanup_old_logs() {
    $old = get_posts(['post_type' => 'fswp_log', 'posts_per_page' => -1, 'date_query' => [['before'=>'15 days ago']], 'fields' => 'ids']);
    foreach($old as $id) wp_delete_post($id, true);
}

function rene_surveys_render_builder() {
    include plugin_dir_path(__FILE__) . 'admin/builder.php';
}

// 2. Shortcode para renderizar o form [render_survey page_slug="vinci"]
add_shortcode('render_survey', 'rene_surveys_render_shortcode');
function rene_surveys_render_shortcode($atts) {
    $atts = shortcode_atts(array(
        'page_slug' => ''
    ), $atts, 'render_survey');

    $slug = $atts['page_slug'];
    if (empty($slug)) {
        global $post;
        $slug = $post->post_name; // Usa a slug da página atual se não for passada
    }

    // Busca o CPT "questionarios" que tem a identificação/slug
    $args = array(
        'post_type' => 'questionarios', // Assumindo que este CPT já existe pelo JetEngine
        'meta_key' => 'page_slug',
        'meta_value' => $slug,
        'posts_per_page' => 1
    );
    
    $query = new WP_Query($args);
    $questions_json = '[]';
    
    if ($query->have_posts()) {
        $query->the_post();
        $questions_json = get_post_meta(get_the_ID(), 'questions_data', true);
        $config_json    = get_post_meta(get_the_ID(), 'survey_config', true)
                       ?: get_post_meta(get_the_ID(), 'surveyform_description', true)
                       ?: '{}';
        wp_reset_postdata();
    } else {
        return '<div class="rene-survey-not-found">🚧 Esta pesquisa ainda está em criação. Em breve estará disponível.</div>';
    }

    ob_start();
    ?>
    <div class="rene-survey-container"
         data-slug="<?php echo esc_attr($slug); ?>"
         data-questions="<?php echo esc_attr($questions_json); ?>"
         data-config="<?php echo esc_attr($config_json); ?>">
        <div id="rene-survey-app"></div>
    </div>
    <?php
    return ob_get_clean(); // ← corrigido: retorna o buffer já aberto acima
}

// 3. Endpoint Ajax para receber as respostas
add_action('wp_ajax_rene_submit_survey', 'rene_handle_survey_submission');
add_action('wp_ajax_nopriv_rene_submit_survey', 'rene_handle_survey_submission');

function rene_handle_survey_submission() {
    check_ajax_referer('rene_survey_nonce', 'nonce');

    $slug = isset($_POST['slug']) ? sanitize_text_field($_POST['slug']) : 'default';
    $answers = isset($_POST['answers']) ? stripslashes($_POST['answers']) : '{}';

    // Cria o post de resposta! CPT: "respostas_survey" (Deve ser criado no JetEngine)
    $post_id = wp_insert_post(array(
        'post_title'    => '#' . $slug . ' - Responder #' . time(),
        'post_type'     => 'respostas_survey',
        'post_status'   => 'publish',
    ));

    if (!is_wp_error($post_id)) {
        // Salva metadados da resposta
        update_post_meta($post_id, 'survey_answers', $answers);
        update_post_meta($post_id, 'page_slug', $slug);
        
        // Inicializa status de sincronização (JetEngine)
        update_post_meta($post_id, 'excel_sync_status', 'pending');
        update_post_meta($post_id, 'excel_sync_date', '');

        // Log opcional para integração
        do_action('rene_survey_saved', $post_id, $slug, $answers);

        wp_send_json_success(array('message' => 'Pesquisa enviada com sucesso!', 'post_id' => $post_id));
    } else {
        wp_send_json_error(array('message' => 'Erro ao salvar a pesquisa.'));
    }
}

// 5. Painel de Fluxo para Admin (Front-end) — visível só para alefxcosta@gmail.com
add_action('wp_footer', 'formsync_render_admin_flow_panel');
function formsync_render_admin_flow_panel() {
    if (!is_user_logged_in()) return;

    $user = wp_get_current_user();
    if (!in_array('administrator', $user->roles) || $user->user_email !== 'alefxcosta@gmail.com') return;
    ?>
    <div id="fswp-flow-panel" style="display:none;">
        <div class="fswp-panel-header">
            <span>📊 FormSync Excel WP — Fluxo</span>
            <button onclick="document.getElementById('fswp-flow-panel').style.display='none'" title="Fechar">✕</button>
        </div>
        <div class="fswp-panel-body">
            <div class="fswp-step">
                <div class="fswp-step-num">1</div>
                <div class="fswp-step-info">
                    <strong>Criar Questionário</strong>
                    <span>WP Admin → Survey Builder<br>Informe o slug e adicione as questões</span>
                </div>
            </div>
            <div class="fswp-arrow">↓</div>
            <div class="fswp-step">
                <div class="fswp-step-num">2</div>
                <div class="fswp-step-info">
                    <strong>Linkar com a Página</strong>
                    <span>Shortcode: <code>[render_survey page_slug="slug"]</code><br>ou Widget no Elementor</span>
                </div>
            </div>
            <div class="fswp-arrow">↓</div>
            <div class="fswp-step">
                <div class="fswp-step-num">3</div>
                <div class="fswp-step-info">
                    <strong>Usuário Responde</strong>
                    <span>Respostas salvas no CPT <code>respostas_survey</code></span>
                </div>
            </div>
            <div class="fswp-arrow">↓</div>
            <div class="fswp-step">
                <div class="fswp-step-num">4</div>
                <div class="fswp-step-info">
                    <strong>Sync com Excel</strong>
                    <span>Cron roda a cada 1h e envia para a planilha</span>
                </div>
            </div>
            <div class="fswp-footer-note">⚠️ CPTs necessários no JetEngine: <code>questionarios</code> e <code>respostas_survey</code></div>
        </div>
    </div>

    <button id="fswp-flow-toggle" onclick="
        var p = document.getElementById('fswp-flow-panel');
        p.style.display = p.style.display === 'none' ? 'block' : 'none';
    " title="FormSync — Fluxo do Plugin">📊</button>

    <style>
    #fswp-flow-toggle {
        position: fixed;
        bottom: 24px;
        right: 24px;
        z-index: 99999;
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: #8257e5;
        color: #fff;
        border: none;
        font-size: 1.3rem;
        cursor: pointer;
        box-shadow: 0 4px 16px rgba(130,87,229,0.5);
        transition: transform 0.2s, background 0.2s;
    }
    #fswp-flow-toggle:hover { background: #996dff; transform: scale(1.1); }

    #fswp-flow-panel {
        position: fixed;
        bottom: 82px;
        right: 24px;
        z-index: 99998;
        width: 320px;
        background: #1a1a1e;
        border: 1px solid #323238;
        border-radius: 12px;
        box-shadow: 0 16px 48px rgba(0,0,0,0.6);
        font-family: 'Inter', sans-serif;
        overflow: hidden;
        animation: fswp-fadein 0.2s ease;
    }
    @keyframes fswp-fadein { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }

    .fswp-panel-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 14px 16px;
        background: #8257e5;
        color: #fff;
        font-weight: 700;
        font-size: 0.88rem;
    }
    .fswp-panel-header button {
        background: transparent;
        border: none;
        color: #fff;
        font-size: 1rem;
        cursor: pointer;
        opacity: 0.8;
        line-height: 1;
    }
    .fswp-panel-header button:hover { opacity: 1; }

    .fswp-panel-body { padding: 16px; }

    .fswp-step {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        background: #121214;
        border: 1px solid #323238;
        border-radius: 8px;
        padding: 12px;
    }
    .fswp-step-num {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: #8257e5;
        color: #fff;
        font-weight: 700;
        font-size: 0.85rem;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .fswp-step-info { display: flex; flex-direction: column; gap: 4px; }
    .fswp-step-info strong { color: #e1e1e6; font-size: 0.88rem; }
    .fswp-step-info span { color: #a9a9b2; font-size: 0.78rem; line-height: 1.4; }
    .fswp-step-info code { background: #323238; padding: 1px 5px; border-radius: 4px; font-size: 0.75rem; color: #996dff; }

    .fswp-arrow { text-align: center; color: #8257e5; font-size: 1.1rem; margin: 4px 0; }

    .fswp-footer-note {
        margin-top: 12px;
        padding: 10px;
        background: rgba(130,87,229,0.08);
        border: 1px solid rgba(130,87,229,0.2);
        border-radius: 8px;
        color: #a9a9b2;
        font-size: 0.75rem;
        line-height: 1.5;
    }
    .fswp-footer-note code { color: #996dff; background: #323238; padding: 1px 4px; border-radius: 3px; }
    </style>
    <?php
}

// 4. Salvar Questionário (Configuração) via Ajax
add_action('wp_ajax_rene_save_questionnaire', 'rene_handle_save_questionnaire');
function rene_handle_save_questionnaire() {
    check_ajax_referer('rene_builder_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Acesso negado.'));
    }

    $slug = sanitize_text_field($_POST['slug']);
    $data = stripslashes($_POST['questions_data']);

    // Verifica se já existe um post para esta slug
    $existing = new WP_Query(array(
        'post_type' => 'questionarios',
        'meta_key' => 'page_slug',
        'meta_value' => $slug,
        'posts_per_page' => 1
    ));

    if ($existing->have_posts()) {
        if (!empty($_POST['is_new'])) {
            wp_send_json_error(array('message' => 'Este slug já existe! Por favor, escolha um slug diferente para a nova pesquisa.'));
        }
        $existing->the_post();
        $post_id = get_the_ID();
        wp_update_post(array(
            'ID' => $post_id,
            'post_title' => 'Questionário: ' . strtoupper($slug)
        ));
    } else {
        $post_id = wp_insert_post(array(
            'post_type' => 'questionarios',
            'post_title' => 'Questionário: ' . strtoupper($slug),
            'post_status' => 'publish'
        ));
    }

    if (!is_wp_error($post_id)) {
        update_post_meta($post_id, 'page_slug', $slug);
        update_post_meta($post_id, 'questions_data', $data);
        // Salva config no survey_config (interno) E no surveyform_description (JetEngine)
        $config_raw = isset($_POST['config_data']) ? stripslashes($_POST['config_data']) : '{}';
        update_post_meta($post_id, 'survey_config', $config_raw);
        update_post_meta($post_id, 'surveyform_description', $config_raw);
        wp_send_json_success(array('message' => 'Questionário salvo!', 'id' => $post_id));
    } else {
        wp_send_json_error(array('message' => 'Erro ao criar/atualizar Post.'));
    }
}

// ═══════════════════════════════════════════════════════════════
// 6. AJAX: Listar todos os questionários (front-end builder)
// ═══════════════════════════════════════════════════════════════
add_action('wp_ajax_formsync_get_surveys', 'formsync_ajax_get_surveys');
function formsync_ajax_get_surveys() {
    check_ajax_referer('rene_builder_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Acesso negado.']);

    $posts = get_posts([
        'post_type'      => 'questionarios',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);

    $surveys = [];
    foreach ($posts as $post) {
        $cfg = get_post_meta($post->ID, 'survey_config', true)
            ?: get_post_meta($post->ID, 'surveyform_description', true)
            ?: '{}';
        
        // Se o WordPress retornar como array (serializado), converte para JSON string
        // para que o JS openEditor(..., cfgJson) funcione corretamente com JSON.parse
        if (is_array($cfg) || is_object($cfg)) {
            $cfg = json_encode($cfg);
        }

        $surveys[] = [
            'id'        => $post->ID,
            'title'     => $post->post_title,
            'slug'      => get_post_meta($post->ID, 'page_slug', true),
            'questions' => get_post_meta($post->ID, 'questions_data', true) ?: '[]',
            'config'    => $cfg,
        ];
    }
    wp_send_json_success($surveys);
}

// ═══════════════════════════════════════════════════════════════
// 7. Front-end Builder Popup — visível só para alefxcosta@gmail.com
// ═══════════════════════════════════════════════════════════════
add_action('wp_footer', 'formsync_render_frontend_builder', 20);
function formsync_render_frontend_builder() {
    if (!is_user_logged_in()) return;
    $user = wp_get_current_user();
    if (!in_array('administrator', $user->roles) || $user->user_email !== 'alefxcosta@gmail.com') return;

    $nonce    = wp_create_nonce('rene_builder_nonce');
    $ajax_url = admin_url('admin-ajax.php');
    ?>

    <div id="fswp-bl-overlay" style="display:none" aria-modal="true" role="dialog">
        <div id="fswp-bl-modal">
            <div class="fswp-bl-header">
                <span>📋 FormSync Builder</span>
                <button id="fswp-bl-close">✕</button>
            </div>

            <div id="fswp-view-list">
                <div class="fswp-bl-toolbar">
                    <p class="fswp-bl-hint">Selecione uma pesquisa ou crie uma nova.</p>
                    <button id="fswp-btn-new" class="fswp-btn-primary">+ Nova Pesquisa</button>
                </div>
                <div id="fswp-surveys-list"><p class="fswp-bl-loading">Carregando…</p></div>
            </div>

            <div id="fswp-view-editor" style="display:none">
                <div class="fswp-bl-toolbar">
                    <button id="fswp-btn-back" class="fswp-btn-ghost">← Voltar</button>
                    <div class="fswp-slug-wrap">
                        <label for="fswp-edit-slug">Slug:</label>
                        <input type="text" id="fswp-edit-slug" placeholder="ex: vinci">
                    </div>
                </div>

                <!-- Config: Página de Apresentação -->
                <details class="fswp-config-section" open>
                    <summary class="fswp-config-summary">📰 Página de Apresentação</summary>
                    <div class="fswp-config-body">
                        <div class="fswp-cfg-row" style="background: rgba(130, 87, 229, 0.05); padding: 15px; border-radius: 10px; border: 1px solid rgba(130, 87, 229, 0.15); margin-bottom: 25px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
                            <label style="color: #a38cf4; font-weight: 600; display: flex; align-items: center; gap: 6px; margin-bottom: 8px;">
                                <span style="font-size: 1.1rem;">🔗</span> Planilha Webhook URL (Google Apps Script)
                            </label>
                            <input type="text" id="cfg-spreadsheet-url" placeholder="https://script.google.com/macros/s/.../exec" style="background: #151518; border: 1px solid #323238; color: #fff; padding: 10px; border-radius: 6px; width: 100%; margin-bottom: 5px;">
                            <p style="font-size:0.75rem; color:#7a88a8; margin: 0 0 15px 0; opacity: 0.8;">💡 As respostas serão enviadas via parâmetros GET para este endpoint.</p>
                            
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 20px; padding-top: 15px; border-top: 1px solid rgba(130,87,229,0.1);">
                                <div style="flex: 1;">
                                    <label style="font-size:0.75rem; color:#a38cf4; display: block; margin-bottom: 5px; font-weight: 600;">⏱️ Frequência</label>
                                    <div style="display: flex; gap: 5px;">
                                        <input type="number" id="cfg-sync-interval" value="5" min="1" style="width: 60px; height: 34px; background: #1e1e22; border: 1px solid #323238; color: #fff; border-radius: 4px; text-align: center;">
                                        <select id="cfg-sync-unit" style="flex: 1; height: 34px; background: #1e1e22; border: 1px solid #323238; color: #fff; border-radius: 4px; padding: 0 8px; font-size: 0.85rem;">
                                            <option value="seconds">Segundos</option>
                                            <option value="minutes" selected>Minutos</option>
                                            <option value="hours">Horas</option>
                                        </select>
                                    </div>
                                </div>
                                <div style="display: flex; flex-direction: column; align-items: flex-end;">
                                    <label style="font-size:0.75rem; color:#a38cf4; margin-bottom: 8px; font-weight: 600;">Estado do Sync</label>
                                    <label class="fswp-switch">
                                        <input type="checkbox" id="cfg-sync-enabled" checked>
                                        <span class="fswp-slider round"></span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <style>
                        /* Switch Premium Toggle */
                        .fswp-switch { position: relative; display: inline-block; width: 44px; height: 22px; }
                        .fswp-switch input { opacity: 0; width: 0; height: 0; }
                        .fswp-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #323238; transition: .3s; border-radius: 34px; }
                        .fswp-slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 3px; bottom: 3px; background-color: #fff; transition: .3s; border-radius: 50%; }
                        input:checked + .fswp-slider { background-color: #8257e5; }
                        input:checked + .fswp-slider:before { transform: translateX(22px); }

                        /* Esconder setas do input number */
                        input#cfg-sync-interval::-webkit-outer-spin-button,
                        input#cfg-sync-interval::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
                        input#cfg-sync-interval { -moz-appearance: textfield; }
                        </style>
                        <div class="fswp-cfg-row">
                            <label>Logo Esquerda (URL da imagem — fixo SSP/seu logo)</label>
                            <input type="text" id="cfg-logo-left" placeholder="https://ssp.seg.br/.../logo.png">
                        </div>
                        <div class="fswp-cfg-row">
                            <label>Logo Direita (URL logo do cliente)</label>
                            <div style="display:flex; gap:10px;">
                                <input type="text" id="cfg-logo-right" placeholder="https://...cliente-logo.png" style="flex:1;">
                                <label style="display:flex;align-items:center;gap:6px;margin:0;color:#e1e1e6;cursor:pointer;white-space:nowrap;" title="Use para dar zoom automático em logos mal recortadas (com muito fundo transparente)">
                                    <input type="checkbox" id="cfg-logo-right-cover" style="width:auto;margin:0;"> Expandir
                                </label>
                            </div>
                        </div>
                        <div class="fswp-cfg-row">
                            <label>Título principal (H1 branco no topo azul)</label>
                            <input type="text" id="cfg-title" placeholder="Ex: CPFL — PESQUISA DE PERCEPÇÃO DE SS">
                            <p style="font-size:.72rem;color:#7a88a8;margin:3px 0 0 0;">💡 **negrito**, ***azul***</p>
                        </div>
                        <div class="fswp-cfg-row">
                            <label>Subtítulo (azul, abaixo)</label>
                            <input type="text" id="cfg-subtitle" placeholder="Ex: Sua Percepção Sobre Segurança e Saúde">
                            <p style="font-size:.72rem;color:#7a88a8;margin:3px 0 0 0;">💡 **negrito**, ***azul***</p>
                        </div>
                        <div class="fswp-cfg-row" style="display:flex; gap:14px;">
                            <div class="fswp-date-field" style="flex:1;">
                                <label>Data de Início (Hoje: <?php echo date('d/m/Y'); ?>)</label>
                                <input id="cfg-start-date" type="date">
                            </div>
                            <div class="fswp-date-field" style="flex:1;">
                                <label>Data de Término (Opcional)</label>
                                <input id="cfg-end-date" type="date">
                            </div>
                        </div>
                        <div class="fswp-cfg-row">
                            <label>Descrição</label>
                            <textarea id="cfg-description" rows="12" placeholder="Cole o texto aqui. Use **negrito** para destacar. Enter para quebrar linha." style="font-family:monospace;resize:vertical;line-height:1.6;"></textarea>
                            <p style="font-size:.82rem;color:#a9a9b2;margin:4px 0 0 0;">💡 **negrito**, ***azul***. Enter para quebrar linha.</p>
                        </div>
                    </div>
                </details>

                <!-- Questões -->
                <div class="fswp-section-label">☱ Questões</div>
                <div id="fswp-q-container"></div>
                <div class="fswp-editor-footer">
                    <div style="display:flex;gap:8px;">
                        <button id="fswp-btn-add-q" class="fswp-btn-ghost">+ Questão</button>
                        <button id="fswp-btn-add-title" class="fswp-btn-ghost" style="color:#8ba0cc;">T + Título</button>
                        <button id="fswp-btn-add-pb" class="fswp-btn-ghost fswp-btn-pb">≡ + Página</button>
                    </div>
                    <button id="fswp-btn-save" class="fswp-btn-primary">💾 Salvar</button>
                </div>
            </div>
        </div>
    </div>

    <button id="fswp-bl-toggle" title="Abrir Builder de Pesquisas">✏️</button>

    <style>
    #fswp-bl-toggle {
        position:fixed;bottom:80px;right:24px;z-index:99999;
        width:48px;height:48px;border-radius:50%;
        background:#2d2d35;color:#fff;border:1px solid #444;
        font-size:1.2rem;cursor:pointer;
        box-shadow:0 4px 12px rgba(0,0,0,.4);
        transition:transform .2s,background .2s;
    }
    #fswp-bl-toggle:hover{background:#3d3d48;transform:scale(1.1);}
    #fswp-bl-overlay{
        position:fixed;inset:0;z-index:99997;
        background:rgba(0,0,0,.75);backdrop-filter:blur(4px);
        display:flex;align-items:center;justify-content:center;padding:20px;
    }
    #fswp-bl-modal{
        background:#1a1a1e;border:1px solid #323238;border-radius:14px;
        width:100%;max-width:900px;max-height:88vh;
        display:flex;flex-direction:column;
        box-shadow:0 24px 64px rgba(0,0,0,.7);
        overflow:hidden;font-family:'Inter',sans-serif;
        animation:fswp-si .2s ease;
    }
    @keyframes fswp-si{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
    .fswp-bl-header{
        display:flex;justify-content:space-between;align-items:center;
        padding:16px 20px;background:#8257e5;color:#fff;
        font-weight:700;font-size:.95rem;flex-shrink:0;
    }
    .fswp-bl-header button{
        background:transparent;border:none;color:rgba(255,255,255,.8);
        font-size:1.1rem;cursor:pointer;padding:2px 6px;border-radius:4px;
    }
    .fswp-bl-header button:hover{background:rgba(255,255,255,.15);color:#fff;}
    #fswp-view-list,#fswp-view-editor{overflow-y:auto;flex:1;padding:16px 20px;}
    #fswp-view-editor{display:flex;flex-direction:column;}
    .fswp-bl-toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;gap:10px;flex-wrap:wrap;}
    .fswp-bl-hint{color:#a9a9b2;font-size:.82rem;margin:0;}
    .fswp-bl-loading,.fswp-bl-empty{color:#a9a9b2;font-size:.88rem;text-align:center;padding:32px 0;}
    .fswp-survey-item{
        display:flex;justify-content:space-between;align-items:center;
        background:#121214;border:1px solid #323238;border-radius:8px;
        padding:12px 16px;margin-bottom:10px;transition:border-color .15s;
    }
    .fswp-survey-item:hover{border-color:#8257e5;}
    .fswp-survey-info strong{display:block;color:#e1e1e6;font-size:.9rem;}
    .fswp-survey-info span{color:#8257e5;font-size:.78rem;font-family:monospace;}
    .fswp-slug-wrap input[readonly]{opacity:.5;cursor:not-allowed;}
    .fswp-date-field{display:flex;flex-direction:column;gap:3px;}
    .fswp-date-field label{font-size:.65rem;color:#7a88a8;font-weight:600;}
    .fswp-date-field input{
        background:#121214;border:1px solid #323238;border-radius:6px;
        color:#e1e1e6;padding:4px 8px;font-size:.82rem;
    }
    .fswp-q-required-label{
        display:flex;align-items:center;gap:4px;
        font-size:.72rem;color:#a9a9b2;cursor:pointer;
        margin-left:auto;user-select:none;
    }
    .fswp-q-required-label input{margin:0;}
    .fswp-q-card{
        background:#121214;border:1px solid #323238;border-radius:10px;
        margin-bottom:10px;overflow:hidden;
    }
    .fswp-q-header{
        display:flex;align-items:center;gap:8px;
        padding:10px 14px;border-bottom:1px solid #323238;
    }
    .fswp-q-num{
        width:26px;height:26px;border-radius:50%;
        background:#8257e5;color:#fff;font-size:.78rem;font-weight:700;
        display:flex;align-items:center;justify-content:center;flex-shrink:0;
    }
    .fswp-q-type{
        flex:1;background:#1e1e24;border:1px solid #323238;border-radius:6px;
        color:#a9a9b2;font-size:.78rem;padding:4px 8px;cursor:pointer;
    }
    .fswp-btn-rm-q{
        background:transparent;border:none;color:#f75a68;
        cursor:pointer;font-size:.95rem;padding:2px 6px;border-radius:4px;
    }
    .fswp-q-label-wrap{padding:10px 14px;border-bottom:1px solid #323238;}
    .fswp-q-label{
        width:100%;box-sizing:border-box;
        background:#0e0e11;border:1px solid #323238;border-radius:8px;
        color:#e1e1e6;font-size:.92rem;padding:10px 14px;
        transition:border-color .15s;
    }
    .fswp-q-label:focus{outline:none;border-color:#8257e5;box-shadow:0 0 0 2px rgba(130,87,229,.15);}
    .fswp-q-label::placeholder{color:#555;}
    .fswp-q-body{padding:12px 14px;}
    .fswp-opt-row{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
    .fswp-opt-letter{
        width:24px;height:24px;border-radius:50%;background:#2d2d35;
        color:#8257e5;font-size:.75rem;font-weight:700;
        display:flex;align-items:center;justify-content:center;flex-shrink:0;
    }
    .fswp-opt-input{
        flex:1;background:#1e1e24;border:1px solid #323238;border-radius:6px;
        color:#e1e1e6;padding:6px 10px;font-size:.85rem;
    }
    .fswp-opt-input:focus{outline:none;border-color:#8257e5;}
    .fswp-btn-rm-opt{
        background:transparent;border:none;color:#555;
        cursor:pointer;font-size:.85rem;padding:2px 6px;border-radius:4px;
    }
    .fswp-btn-rm-opt:hover{color:#f75a68;}
    .fswp-btn-add-opt{
        background:transparent;border:1px dashed #444;border-radius:6px;
        color:#8257e5;font-size:.8rem;padding:5px 12px;cursor:pointer;margin-top:4px;
    }
    .fswp-btn-add-opt:hover{border-color:#8257e5;background:rgba(130,87,229,.05);}
    .fswp-desc-note{color:#5c5c66;font-size:.82rem;font-style:italic;margin:0;}
    .fswp-editor-footer{
        display:flex;justify-content:space-between;align-items:center;
        padding:14px 20px;border-top:1px solid #323238;
        background:#1a1a1e;flex-shrink:0;margin-top:auto;
    }
    .fswp-btn-primary{
        background:#8257e5;color:#fff;border:none;border-radius:8px;
        padding:8px 18px;font-size:.88rem;font-weight:600;cursor:pointer;
        transition:background .15s,transform .15s;
    }
    .fswp-btn-primary:hover:not(:disabled){background:#996dff;transform:translateY(-1px);}
    .fswp-btn-primary:disabled{opacity:.6;cursor:not-allowed;}
    .fswp-btn-ghost{
        background:transparent;border:1px solid #444;color:#a9a9b2;
        border-radius:8px;padding:7px 14px;font-size:.85rem;cursor:pointer;
        transition:border-color .15s,color .15s;
    }
    .fswp-btn-ghost:hover{border-color:#8257e5;color:#e1e1e6;}
    .fswp-btn-pb{border-color:#2d6a4f;color:#52b788;}
    .fswp-btn-pb:hover{border-color:#52b788 !important;color:#74c69d !important;background:rgba(82,183,136,.05);}
    .fswp-section-title-card{
        display:flex;align-items:center;gap:10px;
        margin:6px 0;padding:10px 14px;
        background:rgba(130,87,229,.06);border:1px dashed #8257e5;
        border-radius:8px;
    }
    .fswp-section-title-input{
        flex:1;background:transparent;border:none;
        color:#c8b8ff;font-size:.9rem;font-weight:700;
        outline:none;
    }
    .fswp-section-title-input::placeholder{color:#555;font-weight:400;text-transform:none;}
    .fswp-pb-divider{
        display:flex;align-items:center;gap:10px;
        margin:6px 0;padding:10px 14px;
        background:rgba(82,183,136,.06);border:1px dashed #2d6a4f;
        border-radius:8px;color:#52b788;font-size:.8rem;font-weight:600;
    }
    .fswp-pb-divider span{flex:1;}
    .fswp-btn-rm-pb{background:transparent;border:none;color:#555;cursor:pointer;font-size:.85rem;padding:2px 6px;border-radius:4px;}
    .fswp-btn-rm-pb:hover{color:#f75a68;}
    /* Drag & drop */
    .fswp-drag-handle{
        cursor:grab;color:#444;font-size:1rem;padding:0 6px;
        flex-shrink:0;user-select:none;transition:color .15s;
    }
    .fswp-drag-handle:hover{color:#8257e5;}
    .fswp-drag-handle:active{cursor:grabbing;}
    .fswp-q-card[draggable]{transition:opacity .15s;}
    .fswp-q-card.dragging,.fswp-pb-divider.dragging{opacity:.35;}
    .fswp-q-card.drag-over,.fswp-pb-divider.drag-over{
        border-top:2px solid #8257e5;
    }
    .fswp-config-section{
        border:1px solid #2d3a5e;border-radius:8px;margin-bottom:12px;
        background:#0d0d14;
    }
    .fswp-config-summary{
        display:flex;align-items:center;gap:8px;
        padding:10px 14px;cursor:pointer;font-size:.82rem;
        font-weight:600;color:#8ba0cc;list-style:none;
        user-select:none;
    }
    .fswp-config-summary::-webkit-details-marker{display:none;}
    .fswp-config-summary::before{content:'▶';font-size:.65rem;transition:transform .2s;}
    details[open] .fswp-config-summary::before{transform:rotate(90deg);}
    .fswp-config-body{padding:12px 14px;display:flex;flex-direction:column;gap:10px;border-top:1px solid #1e2a45;}
    #cfg-description{max-height:150px;overflow-y:auto;}
    .fswp-cfg-row label{display:block;color:#7a88a8;font-size:.75rem;margin-bottom:4px;}
    .fswp-cfg-row input,.fswp-cfg-row textarea{
        width:100%;box-sizing:border-box;
        background:#121214;border:1px solid #323238;border-radius:6px;
        color:#e1e1e6;padding:7px 10px;font-size:.85rem;font-family:inherit;
    }
    .fswp-cfg-row input:focus,.fswp-cfg-row textarea:focus{outline:none;border-color:#8257e5;}
    .fswp-cfg-row textarea{resize:vertical;overflow-y:auto !important;min-height:80px;}
    .fswp-section-label{
        font-size:.72rem;font-weight:700;color:#555;
        padding:8px 0 4px;margin-bottom:6px;
        border-top:1px solid #2a2a30;
    }
    </style>

    <script>
    (function(){
        'use strict';
        const NONCE    = <?php echo json_encode($nonce); ?>;
        const AJAX_URL = <?php echo json_encode($ajax_url); ?>;
        const LETTERS  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        let questions  = [];

        function $i(id){ return document.getElementById(id); }
        function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
        function post(data){
            const fd = new FormData();
            Object.entries(data).forEach(([k,v])=>fd.append(k,v));
            return fetch(AJAX_URL,{method:'POST',body:fd}).then(r=>r.json());
        }
        function showView(v){
            $i('fswp-view-list').style.display   = v==='list'   ? 'block' : 'none';
            $i('fswp-view-editor').style.display = v==='editor' ? 'flex'  : 'none';
        }

        // Fix para permitir usar 'Enter' nas textareas sem que o Elementor cancele
        document.querySelectorAll('.fswp-cfg-row textarea').forEach(el=>el.addEventListener('keydown', e=>{
            if(e.key==='Enter') e.stopPropagation();
        }));

        // Toggle
        $i('fswp-bl-toggle').addEventListener('click',()=>{
            const ov=$i('fswp-bl-overlay');
            if(ov.style.display==='none'){ ov.style.display='flex'; loadList(); }
            else ov.style.display='none';
        });
        $i('fswp-bl-close').addEventListener('click',()=>$i('fswp-bl-overlay').style.display='none');

        // List
        function loadList(){
            showView('list');
            $i('fswp-surveys-list').innerHTML='<p class="fswp-bl-loading">Carregando…</p>';
            post({action:'formsync_get_surveys',nonce:NONCE}).then(res=>{
                if(!res.success||!res.data.length){
                    $i('fswp-surveys-list').innerHTML='<p class="fswp-bl-empty">Nenhuma pesquisa cadastrada ainda.</p>';
                    return;
                }
                $i('fswp-surveys-list').innerHTML='';
                res.data.forEach(s=>{
                    const el=document.createElement('div');
                    el.className='fswp-survey-item';
                    el.innerHTML=`<div class="fswp-survey-info"><strong>${esc(s.title)}</strong><span>/${esc(s.slug)}</span></div><button class="fswp-btn-primary" style="flex-shrink:0">Editar</button>`;
                    el.querySelector('button').addEventListener('click',()=>openEditor(s.slug,s.questions,false,s.config||'{}'));
                    $i('fswp-surveys-list').appendChild(el);
                });
            }).catch(()=>{ $i('fswp-surveys-list').innerHTML='<p class="fswp-bl-empty" style="color:#f75a68">Erro ao carregar.</p>'; });
        }

        $i('fswp-btn-new').addEventListener('click',()=>openEditor('','[]',true,'{}'));
        $i('fswp-btn-back').addEventListener('click',loadList);

        // Editor
        function openEditor(slug,qJson,isNew,cfgJson){
            $i('fswp-edit-slug').value=$i('fswp-edit-slug').defaultValue=slug;
            $i('fswp-edit-slug').readOnly=!isNew;
            try{ questions=JSON.parse(qJson||'[]'); }catch{ questions=[]; }
            // Populate config fields
            let cfg = {};
            try {
                if (typeof cfgJson === 'string') {
                    cfg = JSON.parse(cfgJson || '{}');
                } else if (typeof cfgJson === 'object' && cfgJson !== null) {
                    cfg = cfgJson;
                }
            } catch (e) { cfg = {}; }
            $i('cfg-logo-left').value  = cfg.logo_left    || '';
            $i('cfg-spreadsheet-url').value = cfg.spreadsheet_url || '';
            $i('cfg-sync-interval').value = cfg.sync_interval || '5';
            $i('cfg-sync-unit').value = cfg.sync_unit || 'minutes';
            $i('cfg-sync-enabled').checked = (cfg.sync_enabled !== false); // Default true
            $i('cfg-logo-right').value = cfg.logo_right   || '';
            $i('cfg-logo-right-cover').checked = cfg.logo_right_cover || false;
            $i('cfg-title').value      = cfg.title        || '';
            $i('cfg-subtitle').value   = cfg.subtitle     || '';
            $i('cfg-description').value= (cfg.description || '').replace(/\\n/g, '\n');
            $i('cfg-start-date').value = cfg.start_date   || '';
            $i('cfg-end-date').value   = cfg.end_date     || '';
            renderQuestions();
            showView('editor');
        }

        // Limpa paste do Word/Doc na textarea de descrição
        $i('cfg-description').addEventListener('paste', function(e) {
            e.preventDefault();
            const text = (e.clipboardData || window.clipboardData).getData('text/plain');
            const clean = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            const start = this.selectionStart;
            const end   = this.selectionEnd;
            this.value = this.value.slice(0, start) + clean + this.value.slice(end);
            this.selectionStart = this.selectionEnd = start + clean.length;
        });

        $i('fswp-btn-add-q').addEventListener('click',()=>{
            questions.push({id:'q_'+Date.now(),label:'',type:'multiple',options:['','','','','']});
            renderQuestions();
            setTimeout(()=>{ const labels=$i('fswp-q-container').querySelectorAll('.fswp-q-label'); if(labels.length) labels[labels.length-1].focus(); },50);
        });

        $i('fswp-btn-add-title').addEventListener('click',()=>{
            questions.push({id:'title_'+Date.now(),type:'section_title',label:''});
            renderQuestions();
        });

        $i('fswp-btn-add-pb').addEventListener('click',()=>{
            questions.push({id:'pb_'+Date.now(),type:'page_break'});
            renderQuestions();
        });

        function renderQuestions(){
            const c=$i('fswp-q-container');
            c.innerHTML='';
            if(!questions.length){
                c.innerHTML='<p class="fswp-bl-empty">Clique em "+ Questão" para começar.</p>';
                return;
            }
            let pageNum=1; let qNum=0;
            questions.forEach((q,qi)=>{
                if(q.type==='section_title'){
                    const div=document.createElement('div');
                    div.className='fswp-section-title-card';
                    div.setAttribute('draggable','false');
                    div.dataset.qi=qi;
                    div.innerHTML=`<span class="fswp-drag-handle" title="Arraste">⠿</span><input type="text" class="fswp-q-label fswp-section-title-input" data-qi="${qi}" value="${esc(q.label)}" placeholder="Título da seção (ex: A. PERCEPÇÃO SOBRE SUA EMPRESA)"><button class="fswp-btn-rm-q" data-qi="${qi}" title="Remover">🗑</button>`;
                    c.appendChild(div);
                    return;
                }
                if(q.type==='page_break'){
                    const div=document.createElement('div');
                    div.className='fswp-pb-divider';
                    div.setAttribute('draggable','false');
                    div.dataset.qi=qi;
                    div.innerHTML=`<span class="fswp-drag-handle" title="Arraste para reposicionar">⠿</span><span>≡ Quebra de Página — início da página ${++pageNum}</span><button class="fswp-btn-rm-pb" data-qi="${qi}" title="Remover quebra">✕</button>`;
                    c.appendChild(div);
                    return;
                }
                qNum++;
                const card=document.createElement('div');
                card.className='fswp-q-card';
                card.setAttribute('draggable','false');
                card.dataset.qi=qi;

                const optsHtml = q.type==='multiple'
                    ? `<div class="fswp-opts">
                        ${(q.options||[]).map((opt,oi)=>`
                            <div class="fswp-opt-row">
                                <span class="fswp-opt-letter">${LETTERS[oi]||oi+1}</span>
                                <input class="fswp-opt-input" type="text" data-qi="${qi}" data-oi="${oi}" value="${esc(opt)}" placeholder="Opção ${LETTERS[oi]||oi+1} (deixe vazio para ignorar)">
                                <button class="fswp-btn-rm-opt" data-qi="${qi}" data-oi="${oi}" title="Remover opção">✕</button>
                            </div>`).join('')}
                        <button class="fswp-btn-add-opt" data-qi="${qi}">+ Opção</button>
                       </div>`
                    : `<p class="fswp-desc-note">↳ Campo de texto livre para o respondente</p>`;

                card.innerHTML=`
                    <div class="fswp-q-header">
                        <span class="fswp-drag-handle" title="Arraste para reposicionar">⠿</span>
                        <span class="fswp-q-num">${qNum}</span>
                        <select class="fswp-q-type" data-qi="${qi}">
                            <option value="multiple"${q.type==='multiple'?' selected':''}>Múltipla Escolha</option>
                            <option value="text"${q.type==='text'?' selected':''}>Descritiva</option>
                        </select>
                        <label class="fswp-q-required-label">
                            <input type="checkbox" class="fswp-q-required" data-qi="${qi}" ${q.required?'checked':''}> Obrigatória
                        </label>
                        <button class="fswp-btn-rm-q" data-qi="${qi}" title="Remover questão">🗑</button>
                    </div>
                    <div class="fswp-q-label-wrap">
                        <input class="fswp-q-label" type="text" data-qi="${qi}" value="${esc(q.label)}" placeholder="Digite a pergunta aqui…">
                    </div>
                    <div class="fswp-q-body">${optsHtml}</div>`;
                c.appendChild(card);
            });

            // Ativa drag apenas pelo handle
            c.querySelectorAll('.fswp-drag-handle').forEach(h => {
                const parent = h.closest('[draggable]');
                h.addEventListener('mousedown', () => { parent.setAttribute('draggable', 'true'); });
                h.addEventListener('mouseup',   () => { parent.setAttribute('draggable', 'false'); });
                h.addEventListener('mouseleave',() => { parent.setAttribute('draggable', 'false'); });
            });

            // ── Drag & Drop ──────────────────────────────────────────────
            let dragSrcIdx = null;
            c.querySelectorAll('[draggable]').forEach(el=>{
                el.addEventListener('dragstart',function(e){
                    dragSrcIdx = +this.dataset.qi;
                    this.classList.add('dragging');
                    e.dataTransfer.effectAllowed='move';
                });
                el.addEventListener('dragend',function(){
                    this.classList.remove('dragging');
                    this.setAttribute('draggable', 'false'); // Reseta ao terminar
                    c.querySelectorAll('.drag-over').forEach(x=>x.classList.remove('drag-over'));
                });
                el.addEventListener('dragover',function(e){
                    e.preventDefault();
                    e.dataTransfer.dropEffect='move';
                    c.querySelectorAll('.drag-over').forEach(x=>x.classList.remove('drag-over'));
                    this.classList.add('drag-over');
                });
                el.addEventListener('drop',function(e){
                    e.preventDefault();
                    const targetIdx = +this.dataset.qi;
                    if(dragSrcIdx === null || dragSrcIdx === targetIdx) return;
                    // Reorder
                    const moved = questions.splice(dragSrcIdx,1)[0];
                    const insertAt = dragSrcIdx < targetIdx ? targetIdx - 1 : targetIdx;
                    questions.splice(insertAt,0,moved);
                    dragSrcIdx = null;
                    renderQuestions();
                });
            });

            // ── Outros eventos ───────────────────────────────────────────
            c.querySelectorAll('.fswp-btn-rm-pb').forEach(el=>el.addEventListener('click',function(){ questions.splice(+this.dataset.qi,1); renderQuestions(); }));
            c.querySelectorAll('.fswp-q-label').forEach(el=>el.addEventListener('input',function(){ questions[+this.dataset.qi].label=this.value; }));
            c.querySelectorAll('.fswp-q-required').forEach(el=>el.addEventListener('change',function(){ questions[+this.dataset.qi].required=this.checked; }));
            c.querySelectorAll('.fswp-q-type').forEach(el=>el.addEventListener('change',function(){
                const q=questions[+this.dataset.qi];
                q.type=this.value;
                if(q.type==='multiple'&&!q.options?.length) q.options=[''];
                renderQuestions();
            }));
            c.querySelectorAll('.fswp-btn-rm-q').forEach(el=>el.addEventListener('click',function(){
                questions.splice(+this.dataset.qi,1); renderQuestions();
            }));
            c.querySelectorAll('.fswp-opt-input').forEach(el=>el.addEventListener('input',function(){ questions[+this.dataset.qi].options[+this.dataset.oi]=this.value; }));
            c.querySelectorAll('.fswp-btn-add-opt').forEach(el=>el.addEventListener('click',function(){ questions[+this.dataset.qi].options.push(''); renderQuestions(); }));
            c.querySelectorAll('.fswp-btn-rm-opt').forEach(el=>el.addEventListener('click',function(){ questions[+this.dataset.qi].options.splice(+this.dataset.oi,1); renderQuestions(); }));
        }

        // Save — filtra opções em branco e inclui config
        $i('fswp-btn-save').addEventListener('click',function(){
            const slug=$i('fswp-edit-slug').value.trim();
            if(!slug){ alert('Informe o slug da pesquisa!'); return; }
            const payload = questions.map(q => ({
                ...q,
                options: q.type==='multiple' ? (q.options||[]).filter(o=>o.trim()!=='') : []
            }));
            // Lê campos de config
            const config = {
                logo_left       : $i('cfg-logo-left').value.trim(),
                spreadsheet_url : $i('cfg-spreadsheet-url').value.trim(),
                sync_interval   : $i('cfg-sync-interval').value || '5',
                sync_unit       : $i('cfg-sync-unit').value || 'minutes',
                sync_enabled    : $i('cfg-sync-enabled').checked,
                logo_right      : $i('cfg-logo-right').value.trim(),
                logo_right_cover: $i('cfg-logo-right-cover').checked,
                title           : $i('cfg-title').value.trim(),
                subtitle        : $i('cfg-subtitle').value.trim(),
                description     : $i('cfg-description').value.replace(/\n/g, '\\n'),
                start_date      : $i('cfg-start-date').value,
                end_date        : $i('cfg-end-date').value,
            };
            const isNew = !$i('fswp-edit-slug').readOnly;
            const btn=this;
            btn.disabled=true; btn.textContent='Salvando…';

            post({
                action:'rene_save_questionnaire',nonce:NONCE,slug,
                is_new: isNew ? 1 : 0,
                questions_data:JSON.stringify(payload),
                config_data:JSON.stringify(config),
                surveyform_description:JSON.stringify(config)
            }).then(res=>{
                if(res.success){
                    btn.textContent='✅ Salvo!';
                    $i('fswp-edit-slug').readOnly=true;
                    setTimeout(()=>{ btn.disabled=false; btn.textContent='💾 Salvar'; },2200);
                } else {
                    alert('Erro: '+(res.data?.message||'desconhecido'));
                    btn.disabled=false; btn.textContent='💾 Salvar';
                }
            }).catch(()=>{ alert('Erro na comunicação.'); btn.disabled=false; btn.textContent='💾 Salvar'; });
        });
    })();
    </script>
    <?php
}
