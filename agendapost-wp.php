<?php
/**
 * Plugin Name: Post Expiration
 * Description: Permite definir uma data de expiração para posts, retirando-os do ar automaticamente.
 * Version: 1.0
 * Author: Seu Nome
 */

// Previne acesso direto
if (!defined('ABSPATH')) {
    exit;
}

class PostExpiration {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
    }
    
    public function init() {
        // Adiciona meta box no editor de posts
        add_action('add_meta_boxes', array($this, 'add_expiration_meta_box'));
        
        // Salva dados do meta box
        add_action('save_post', array($this, 'save_expiration_date'));
        
        // Adiciona coluna na listagem de posts
        add_filter('manage_posts_columns', array($this, 'add_expiration_column'));
        add_action('manage_posts_custom_column', array($this, 'show_expiration_column'), 10, 2);
        
        // Torna a coluna ordenável
        add_filter('manage_edit-post_sortable_columns', array($this, 'make_expiration_column_sortable'));
        add_action('pre_get_posts', array($this, 'expiration_column_orderby'));
        
        // Hook para verificar posts expirados
        add_action('wp', array($this, 'check_expired_posts'));
        
        // Agenda evento cron para verificação automática
        add_action('wp', array($this, 'schedule_expiration_check'));
        add_action('post_expiration_check', array($this, 'expire_posts'));
        
        // Filtros para ocultar posts expirados
        add_action('pre_get_posts', array($this, 'hide_expired_posts'));
        
        // Protege acesso direto a posts expirados (menus, URLs diretas)
        add_action('template_redirect', array($this, 'redirect_expired_posts'));
        
        // Remove posts expirados dos menus
        add_filter('wp_get_nav_menu_items', array($this, 'hide_expired_from_menus'), 10, 3);
        
        // Adiciona estilos CSS
        add_action('admin_head', array($this, 'add_admin_styles'));
    }
    
    /**
     * Adiciona meta box no editor de posts
     */
    public function add_expiration_meta_box() {
        add_meta_box(
            'post-expiration',
            'Data de Expiração',
            array($this, 'expiration_meta_box_callback'),
            'post',
            'side',
            'high'
        );
    }
    
    /**
     * Callback do meta box
     */
    public function expiration_meta_box_callback($post) {
        wp_nonce_field('post_expiration_nonce', 'post_expiration_nonce');
        
        $expiration_date = get_post_meta($post->ID, '_expiration_date', true);
        $expiration_time = get_post_meta($post->ID, '_expiration_time', true);
        
        // Converte para formato do input se existe
        if ($expiration_date && $expiration_time) {
            $datetime = $expiration_date . ' ' . $expiration_time;
            // Converte para o timezone local para exibição
            $timestamp = strtotime($datetime);
            $formatted_datetime = date('Y-m-d\TH:i', $timestamp);
        } else {
            $formatted_datetime = '';
        }
        
        echo '<div style="margin: 10px 0;">';
        echo '<label for="expiration_datetime"><strong>Data e Hora de Expiração (UTC-3):</strong></label><br>';
        echo '<input type="datetime-local" id="expiration_datetime" name="expiration_datetime" value="' . esc_attr($formatted_datetime) . '" style="width: 100%; margin-top: 5px;">';
        echo '<p class="description">Deixe em branco para que o post nunca expire. Horário: UTC-3 (Brasília)</p>';
        echo '</div>';
        
        // Mostra status atual
        if ($expiration_date && $expiration_time) {
            $current_time = current_time('timestamp');
            $exp_timestamp = strtotime($expiration_date . ' ' . $expiration_time);
            
            echo '<div style="margin-top: 15px; padding: 10px; border-left: 4px solid ';
            if ($exp_timestamp <= $current_time) {
                echo '#dc3232;"><strong style="color: #dc3232;">⚠ EXPIRADO</strong>';
            } else {
                echo '#46b450;"><strong style="color: #46b450;">✓ ATIVO</strong>';
            }
            echo '<br><small>Expira em: ' . date_i18n('d/m/Y H:i', $exp_timestamp) . ' (UTC-3)</small></div>';
        }
    }
    
    /**
     * Salva a data de expiração
     */
    public function save_expiration_date($post_id) {
        if (!isset($_POST['post_expiration_nonce']) || !wp_verify_nonce($_POST['post_expiration_nonce'], 'post_expiration_nonce')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        if (isset($_POST['expiration_datetime']) && !empty($_POST['expiration_datetime'])) {
            $datetime = sanitize_text_field($_POST['expiration_datetime']);
            $date = date('Y-m-d', strtotime($datetime));
            $time = date('H:i:s', strtotime($datetime));
            
            update_post_meta($post_id, '_expiration_date', $date);
            update_post_meta($post_id, '_expiration_time', $time);
        } else {
            delete_post_meta($post_id, '_expiration_date');
            delete_post_meta($post_id, '_expiration_time');
        }
    }
    
    /**
     * Adiciona coluna na listagem de posts
     */
    public function add_expiration_column($columns) {
        $columns['expiration'] = 'Expiração';
        return $columns;
    }
    
    /**
     * Mostra conteúdo da coluna de expiração
     */
    public function show_expiration_column($column, $post_id) {
        if ($column == 'expiration') {
            $expiration_date = get_post_meta($post_id, '_expiration_date', true);
            $expiration_time = get_post_meta($post_id, '_expiration_time', true);
            
            if ($expiration_date && $expiration_time) {
                $exp_datetime = $expiration_date . ' ' . $expiration_time;
                $exp_timestamp = strtotime($exp_datetime);
                $current_time = current_time('timestamp');
                
                if ($exp_timestamp <= $current_time) {
                    echo '<span class="expired-post">⚠ EXPIRADO</span><br>';
                    echo '<small>' . date_i18n('d/m/Y H:i', $exp_timestamp) . '</small>';
                } else {
                    echo '<span class="active-post">✓ ' . date_i18n('d/m/Y H:i', $exp_timestamp) . '</span>';
                    
                    // Calcula tempo restante
                    $time_diff = $exp_timestamp - $current_time;
                    $days = floor($time_diff / (60 * 60 * 24));
                    
                    if ($days > 0) {
                        echo '<br><small>(' . $days . ' dias restantes)</small>';
                    } else {
                        $hours = floor($time_diff / (60 * 60));
                        if ($hours > 0) {
                            echo '<br><small>(' . $hours . ' horas restantes)</small>';
                        } else {
                            echo '<br><small>(Menos de 1 hora)</small>';
                        }
                    }
                }
            } else {
                echo '<span class="no-expiration">Nunca expira</span>';
            }
        }
    }
    
    /**
     * Torna a coluna ordenável
     */
    public function make_expiration_column_sortable($columns) {
        $columns['expiration'] = 'expiration_date';
        return $columns;
    }
    
    /**
     * Define como ordenar pela coluna de expiração
     */
    public function expiration_column_orderby($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        
        if ('expiration_date' === $query->get('orderby')) {
            $query->set('meta_key', '_expiration_date');
            $query->set('orderby', 'meta_value');
        }
    }
    
    /**
     * Agenda verificação automática de posts expirados
     */
    public function schedule_expiration_check() {
        if (!wp_next_scheduled('post_expiration_check')) {
            wp_schedule_event(time(), 'hourly', 'post_expiration_check');
        }
    }
    
    /**
     * Verifica e expira posts na página atual
     */
    public function check_expired_posts() {
        if (is_admin()) return;
        
        $this->expire_posts();
    }
    
    /**
     * Coloca posts expirados como rascunho
     */
    public function expire_posts() {
        global $wpdb;
        
        // Usar timezone do WordPress
        $current_datetime = current_time('Y-m-d H:i:s');
        
        // Buscar posts publicados que têm data de expiração
        $query = "
            SELECT p.ID, pm1.meta_value as exp_date, pm2.meta_value as exp_time
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_expiration_date'
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_expiration_time'
            WHERE p.post_status = 'publish'
            AND p.post_type = 'post'
            AND CONCAT(pm1.meta_value, ' ', pm2.meta_value) <= %s
        ";
        
        $expired_posts = $wpdb->get_results($wpdb->prepare($query, $current_datetime));
        
        foreach ($expired_posts as $post) {
            wp_update_post(array(
                'ID' => $post->ID,
                'post_status' => 'draft'
            ));
        }
    }
    
    /**
     * Oculta posts expirados das consultas públicas
     */
    public function hide_expired_posts($query) {
        if (is_admin() || !$query->is_main_query()) {
            return;
        }
        
        // Não interfere em páginas específicas de posts
        if (is_single() || is_page()) {
            return;
        }
        
        // Obter posts que têm data de expiração definida
        $expired_posts = $this->get_expired_post_ids();
        
        if (!empty($expired_posts)) {
            $post__not_in = $query->get('post__not_in', array());
            $post__not_in = array_merge($post__not_in, $expired_posts);
            $query->set('post__not_in', $post__not_in);
        }
    }
    
    /**
     * Retorna IDs de posts expirados
     */
    private function get_expired_post_ids() {
        global $wpdb;
        
        // Usar timezone configurado no WordPress
        $current_datetime = current_time('Y-m-d H:i:s');
        
        $query = "
            SELECT p.ID 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_expiration_date'
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_expiration_time'
            WHERE p.post_status = 'publish'
            AND p.post_type = 'post'
            AND CONCAT(pm1.meta_value, ' ', pm2.meta_value) <= %s
        ";
        
        $expired_post_ids = $wpdb->get_col($wpdb->prepare($query, $current_datetime));
        
        return array_map('intval', $expired_post_ids);
    }
    
    /**
     * Redireciona ou mostra 404 para posts expirados acessados diretamente
     */
    public function redirect_expired_posts() {
        if (is_single() && get_post_type() === 'post') {
            $post_id = get_the_ID();
            
            if ($this->is_post_expired($post_id)) {
                // Opção 1: Redirecionar para página inicial
                wp_redirect(home_url());
                exit;
                
                // Opção 2: Mostrar 404 (descomente a linha abaixo e comente as duas acima)
                // global $wp_query;
                // $wp_query->set_404();
            }
        }
    }
    
    /**
     * Remove posts expirados dos menus
     */
    public function hide_expired_from_menus($items, $menu, $args) {
        foreach ($items as $key => $item) {
            // Verifica se é um post
            if ($item->object === 'post' && $item->type === 'post_type') {
                if ($this->is_post_expired($item->object_id)) {
                    unset($items[$key]);
                }
            }
        }
        return $items;
    }
    
    /**
     * Verifica se um post específico está expirado
     */
    private function is_post_expired($post_id) {
        $expiration_date = get_post_meta($post_id, '_expiration_date', true);
        $expiration_time = get_post_meta($post_id, '_expiration_time', true);
        
        if (!$expiration_date || !$expiration_time) {
            return false;
        }
        
        $exp_datetime = $expiration_date . ' ' . $expiration_time;
        $exp_timestamp = strtotime($exp_datetime);
        $current_time = current_time('timestamp');
        
        return $exp_timestamp <= $current_time;
    }
    
    /**
     * Adiciona estilos CSS para o admin
     */
    public function add_admin_styles() {
        echo '<style>
            .expired-post {
                color: #dc3232;
                font-weight: bold;
            }
            .active-post {
                color: #46b450;
                font-weight: bold;
            }
            .no-expiration {
                color: #666;
                font-style: italic;
            }
        </style>';
    }
}

// Ativa o plugin
new PostExpiration();

// Hook de desativação para limpar eventos cron
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('post_expiration_check');
});
?>