<?php
/**
 * Plugin Name: FormSync Excel WP
 * Description: Sistema dinâmico de pesquisas de segurança do trabalho com sincronização para Excel. No Elementor, arraste o widget <strong>FormSync Excel WP</strong>. Em outros construtores, use o shortcode <strong>[render_survey page_slug="slug-da-pagina"]</strong>.
 * Version: 1.0.0
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
    $widgets_manager->register(new SafeSurvey_Elementor_Widget());
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
