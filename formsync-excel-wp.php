<?php
/**
 * Plugin Name: FormSync Excel WP
 * Description: Sistema dinâmico de pesquisas de segurança do trabalho com sincronização para Excel. No Elementor, arraste o widget <strong>FormSync Excel WP</strong>. Em outros construtores, use o shortcode <strong>[render_survey page_slug="slug-da-pagina"]</strong>.
 * Version: 1.0.3
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

// 1. Enfileirar Scripts e Estilos para o Front-end
add_action('wp_enqueue_scripts', 'rene_surveys_enqueue_scripts');
function rene_surveys_enqueue_scripts() {
    // Apenas carrega se estiver usando o shortcode
    global $post;
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'render_survey')) {
        wp_enqueue_style('rene-surveys-style', plugin_dir_url(__FILE__) . 'public/style.css', array(), '1.0');
        wp_enqueue_script('rene-surveys-script', plugin_dir_url(__FILE__) . 'public/script.js', array('jquery'), '1.0', true);
        
        // Passar dados do PHP para o JS
        wp_localize_script('rene-surveys-script', 'ReneSurveysData', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('rene_survey_nonce')
        ));
    }
}

// 1.1 Enfileirar Scripts para o Admin (Builder)
add_action('admin_enqueue_scripts', 'rene_surveys_admin_scripts');
function rene_surveys_admin_scripts($hook) {
    if ('toplevel_page_rene-survey-builder' !== $hook) {
        return;
    }
    wp_enqueue_script('rene-builder-script', plugin_dir_url(__FILE__) . 'admin/builder.js', array('jquery'), '1.0', true);
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
        wp_reset_postdata();
    } else {
        return '<div class="rene-survey-not-found">🚧 Esta pesquisa ainda está em criação. Em breve estará disponível.</div>';
    }

    ob_start();
    ?>
    <div class="rene-survey-container" data-slug="<?php echo esc_attr($slug); ?>" data-questions="<?php echo esc_attr($questions_json); ?>">
        <div class="safesurvey-brand">
            <span class="safesurvey-brand-icon">📊</span>
            <span class="safesurvey-brand-name">FormSync Excel <strong>WP</strong></span>
        </div>
        <div id="rene-survey-app"></div>
    </div>
    <?php
    return ob_start() ? ob_get_clean() : '';
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
        // Salva o JSON no post meta
        update_post_meta($post_id, 'survey_answers', $answers);

        // AQUI ENTRA A INTEGRAÇÃO COM EXCEL
        // do_action('rene_survey_saved', $post_id, $slug, $answers);

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
        wp_send_json_success(array('message' => 'Questionário salvo!', 'id' => $post_id));
    } else {
        wp_send_json_error(array('message' => 'Erro ao criar/atualizar Post.'));
    }
}
