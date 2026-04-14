<?php
/**
 * Widget Elementor: FormSync Excel WP
 * Permite arrastar o formulário de pesquisa direto no Elementor.
 */

if (!defined('ABSPATH')) {
    exit;
}

class FormSync_Elementor_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return 'formsync_ex_wp';
    }

    public function get_title() {
        return 'FormSync Excel WP';
    }

    public function get_icon() {
        return 'eicon-form-horizontal';
    }

    public function get_categories() {
        return ['general'];
    }

    public function get_keywords() {
        return ['survey', 'pesquisa', 'formulário', 'segurança', 'safesurvey'];
    }

    protected function register_controls() {
        $this->start_controls_section(
            'content_section',
            [
                'label' => 'Configuração da Pesquisa',
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'page_slug',
            [
                'label'       => 'Slug da Pesquisa',
                'type'        => \Elementor\Controls_Manager::TEXT,
                'placeholder' => 'ex: vinci',
                'description' => 'Deve ser o mesmo slug cadastrado no CPT Questionários.',
                'default'     => '',
            ]
        );

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $slug = !empty($settings['page_slug']) ? sanitize_text_field($settings['page_slug']) : '';

        if (empty($slug)) {
            echo '<div style="padding:20px;border:2px dashed #8257e5;color:#8257e5;border-radius:8px;text-align:center;">
                    📊 <strong>FormSync Excel WP</strong><br>
                    <small>Informe o slug da pesquisa no painel de configurações do widget.</small>
                  </div>';
            return;
        }

        // Reutiliza o shortcode já existente
        echo do_shortcode('[render_survey page_slug="' . esc_attr($slug) . '"]');
    }
}
