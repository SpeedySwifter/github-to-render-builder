<?php
/*
Plugin Name: GitHub to Render Builder
Description: Verlinkt GitHub Repositories per OAuth und baut sie auf Render.com per API. GitHub Client ID & Secret im Backend einstellbar.
Version: 0.3
Author: Sven Hajer
*/

if (!defined('ABSPATH')) {
    exit; // Sicherheit: Kein direkter Zugriff
}

class GTRB_Plugin {
    private $github_token_option = 'gtrb_github_token';
    private $render_api_key_option = 'gtrb_render_api_key';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_gtrb_github_logout', [$this, 'github_logout']);
        add_action('admin_post_gtrb_save_render_api_key', [$this, 'save_render_api_key']);
        add_action('admin_init', [$this, 'handle_github_oauth_callback']);
    }

    public function add_admin_menu() {
        add_menu_page(
            'GitHub to Render Builder',
            'GTRB Settings',
            'manage_options',
            'gtrb-settings',
            [$this, 'render_settings_page'],
            'dashicons-admin-network',
            80
        );
    }

    public function register_settings() {
        register_setting('gtrb_settings_group', 'gtrb_github_client_id');
        register_setting('gtrb_settings_group', 'gtrb_github_client_secret');
    }

    public function render_settings_page() {
        $github_token = get_option($this->github_token_option);
        $render_api_key = get_option($this->render_api_key_option);
        $client_id = get_option('gtrb_github_client_id');
        $client_secret = get_option('gtrb_github_client_secret');
        ?>
        <div class="wrap">
            <h1>GitHub & Render.com Integration</h1>

            <h2>0. GitHub OAuth App Einstellungen</h2>
            <form method="post" action="options.php">
                <?php settings_fields('gtrb_settings_group'); ?>
                <?php do_settings_sections('gtrb_settings_group'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">GitHub Client ID</th>
                        <td><input type="text" name="gtrb_github_client_id" value="<?php echo esc_attr($client_id); ?>" class="regular-text" required /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">GitHub Client Secret</th>
                        <td><input type="password" name="gtrb_github_client_secret" value="<?php echo esc_attr($client_secret); ?>" class="regular-text" required /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>

            <h2>1. GitHub Login</h2>
            <?php if (!$client_id || !$client_secret): ?>
                <p style="color:red;">Bitte gib zuerst Client ID und Client Secret oben ein und speichere.</p>
            <?php else: ?>
                <?php if (!$github_token): ?>
                    <?php $auth_url = $this->get_github_oauth_url(); ?>
                    <a href="<?php echo esc_url($auth_url); ?>" class="button button-primary">Mit GitHub verbinden</a>
                <?php else: ?>
                    <p><strong>GitHub verbunden.</strong></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('gtrb_github_logout_nonce'); ?>
                        <input type="hidden" name="action" value="gtrb_github_logout" />
                        <input type="submit" value="Abmelden" class="button button-secondary" />
                    </form>

                    <?php
                    $repos = $this->get_github_repos($github_token);
                    if (is_wp_error($repos)) {
                        echo '<p style="color:red;">Fehler: ' . esc_html($repos->get_error_message()) . '</p>';
                    } else {
                        echo '<h3>Deine Repositories:</h3><ul>';
                        foreach ($repos as $repo) {
                            echo '<li>' . esc_html($repo->full_name) . '</li>';
                        }
                        echo '</ul>';
                    }
                    ?>
                <?php endif; ?>
            <?php endif; ?>

            <hr>

            <h2>2. Render.com API-Key</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gtrb_save_render_api_key_nonce'); ?>
                <input type="hidden" name="action" value="gtrb_save_render_api_key" />
                <label for="render_api_key">API-Key:</label>
                <input type="text" name="render_api_key" id="render_api_key" value="<?php echo esc_attr($render_api_key); ?>" class="regular-text" />
                <input type="submit" value="Speichern" class="button button-primary" />
            </form>

            <?php if ($render_api_key): ?>
                <?php
                $services = $this->get_render_services($render_api_key);
                if (is_wp_error($services)) {
                    echo '<p style="color:red;">Fehler: ' . esc_html($services->get_error_message()) . '</p>';
                } else {
                    echo '<h3>Render Services:</h3><ul>';
                    foreach ($services as $service) {
                        echo '<li>' . esc_html($service->name) . ' (ID: ' . esc_html($service->id) . ')</li>';
                    }
                    echo '</ul>';
                }
                ?>
            <?php endif; ?>

        </div>
        <?php
    }

    public function get_github_oauth_url() {
        $client_id = get_option('gtrb_github_client_id');
        $redirect_uri = admin_url('admin.php?page=gtrb-settings&gtrb_github_oauth=1');
        $params = [
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'scope' => 'repo',
            'state' => wp_create_nonce('gtrb_github_oauth_state'),
        ];
        return 'https://github.com/login/oauth/authorize?' . http_build_query($params);
    }

    public function handle_github_oauth_callback() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'gtrb-settings') {
            return;
        }
        if (!isset($_GET['gtrb_github_oauth'])) {
            return;
        }

        if (!isset($_GET['code']) || !isset($_GET['state']) || !wp_verify_nonce($_GET['state'], 'gtrb_github_oauth_state')) {
            wp_die('Ungültige OAuth-Anfrage.');
        }

        $code = sanitize_text_field($_GET['code']);
        $client_id = get_option('gtrb_github_client_id');
        $client_secret = get_option('gtrb_github_client_secret');

        $response = wp_remote_post('https://github.com/login/oauth/access_token', [
            'headers' => ['Accept' => 'application/json'],
            'body' => [
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'code' => $code,
                'redirect_uri' => admin_url('admin.php?page=gtrb-settings&gtrb_github_oauth=1'),
                'state' => $_GET['state'],
            ],
        ]);

        if (is_wp_error($response)) {
            wp_die('Fehler beim Token-Abruf.');
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            wp_die('GitHub Fehler: ' . esc_html($body['error_description']));
        }

        if (!isset($body['access_token'])) {
            wp_die('Kein Access Token erhalten.');
        }

        update_option($this->github_token_option, sanitize_text_field($body['access_token']));

        wp_redirect(admin_url('admin.php?page=gtrb-settings'));
        exit;
    }

    public function github_logout() {
        check_admin_referer('gtrb_github_logout_nonce');
        delete_option($this->github_token_option);
        wp_redirect(admin_url('admin.php?page=gtrb-settings'));
        exit;
    }

    public function save_render_api_key() {
        check_admin_referer('gtrb_save_render_api_key_nonce');
        if (isset($_POST['render_api_key'])) {
            update_option($this->render_api_key_option, sanitize_text_field($_POST['render_api_key']));
        }
        wp_redirect(admin_url('admin.php?page=gtrb-settings'));
        exit;
    }

    public function get_github_repos($token) {
        $response = wp_remote_get('https://api.github.com/user/repos?per_page=100', [
            'headers' => [
                'Authorization' => 'token ' . $token,
                'User-Agent' => 'GTRB Plugin',
            ],
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $repos = json_decode(wp_remote_retrieve_body($response));
        if (!is_array($repos)) {
            return new WP_Error('invalid_response', 'Ungültige API Antwort.');
        }
        return $repos;
    }

    public function get_render_services($api_key) {
        $response = wp_remote_get('https://api.render.com/v1/services', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Accept' => 'application/json',
            ],
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $services = json_decode(wp_remote_retrieve_body($response));
        if (!is_array($services)) {
            return new WP_Error('invalid_response', 'Ungültige API Antwort von Render.');
        }
        return $services;
    }
}

$gtrb_plugin = new GTRB_Plugin();
add_action('admin_init', [$gtrb_plugin, 'handle_github_oauth_callback']);
