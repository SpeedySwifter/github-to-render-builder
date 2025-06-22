<?php
/*
Plugin Name: GitHub to Render Builder
Description: GitHub OAuth + Render.com Integration mit Service-Auswahl im Backend.
Version: 0.6
Author: Sven Hajer
*/

if (!defined('ABSPATH')) {
    exit; // kein direkter Zugriff
}

class GTRB_Plugin {
    private $github_token_option = 'gtrb_github_token';
    private $render_api_key_option = 'gtrb_render_api_key';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_gtrb_github_logout', [$this, 'github_logout']);
        add_action('admin_post_gtrb_save_render_api_key', [$this, 'save_render_api_key']);
        add_action('admin_post_gtrb_save_selected_services', [$this, 'save_selected_services']);
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
        $selected_services = get_option('gtrb_selected_render_services', []);
        $client_id = get_option('gtrb_github_client_id');
        $client_secret = get_option('gtrb_github_client_secret');
        ?>
        <div class="wrap">
            <h1>GitHub & Render.com Integration</h1>

            <h2>Getting Started: How to use this plugin</h2>
            <div style="background:#f1f1f1;padding:15px;margin-bottom:30px;border-left:4px solid #0073aa;">
                <h3>1. Create a GitHub OAuth App</h3>
                <p>Go to <a href="https://github.com/settings/developers" target="_blank" rel="noopener noreferrer">GitHub Developer Settings</a> and create a new OAuth App.<br>
                Set the Redirect URL to:<br>
                <code>https://YOUR-WP-DOMAIN/wp-admin/admin.php?page=gtrb-settings&gtrb_github_oauth=1</code><br>
                Copy your Client ID and Client Secret.</p>

                <h3>2. Enter GitHub OAuth App Credentials</h3>
                <p>Fill in your GitHub Client ID and Client Secret below and save.</p>

                <h3>3. Connect to GitHub</h3>
                <p>Click the <strong>Connect with GitHub</strong> button to log in and authorize access.</p>

                <h3>4. View Your Repositories</h3>
                <p>After login, your GitHub repositories will be displayed.</p>

                <h3>5. Enter Render.com API Key</h3>
                <p>
                  To find your Render API Key, log in to your account at  
                  <a href="https://dashboard.render.com/u/usr-d1ajhmqdbo4c73cict0g/settings" target="_blank" rel="noopener noreferrer">
                    https://dashboard.render.com/u/usr-d1ajhmqdbo4c73cict0g/settings
                  </a>.<br>
                  Then navigate to the <strong>API Keys</strong> section to create or copy your key.
                </p>

                <h3>6. Select Render Services</h3>
                <p>Select which Render services to use for build triggers.</p>

                <h3>7. Logout</h3>
                <p>Use the logout button to disconnect GitHub.</p>
            </div>

            <h2>0. GitHub OAuth App Settings</h2>
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
                <p style="color:red;">Please enter Client ID and Client Secret above and save before connecting to GitHub.</p>
            <?php else: ?>
                <?php if (!$github_token): ?>
                    <?php $auth_url = $this->get_github_oauth_url(); ?>
                    <a href="<?php echo esc_url($auth_url); ?>" class="button button-primary">Connect with GitHub</a>
                <?php else: ?>
                    <p><strong>GitHub connected.</strong></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('gtrb_github_logout_nonce'); ?>
                        <input type="hidden" name="action" value="gtrb_github_logout" />
                        <input type="submit" value="Logout" class="button button-secondary" />
                    </form>

                    <?php
                    $repos = $this->get_github_repos($github_token);
                    if (is_wp_error($repos)) {
                        echo '<p style="color:red;">Error: ' . esc_html($repos->get_error_message()) . '</p>';
                    } else {
                        echo '<h3>Your Repositories:</h3><ul>';
                        foreach ($repos as $repo) {
                            echo '<li>' . esc_html($repo->full_name) . '</li>';
                        }
                        echo '</ul>';
                    }
                    ?>
                <?php endif; ?>
            <?php endif; ?>

            <hr>

            <h2>2. Render.com API Key</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gtrb_save_render_api_key_nonce'); ?>
                <input type="hidden" name="action" value="gtrb_save_render_api_key" />
                <label for="render_api_key">API Key:</label>
                <input type="text" name="render_api_key" id="render_api_key" value="<?php echo esc_attr($render_api_key); ?>" class="regular-text" />
                <input type="submit" value="Save" class="button button-primary" />
            </form>

            <?php if ($render_api_key): ?>
                <?php
                $services = $this->get_render_services($render_api_key);
                if (is_wp_error($services)) {
                    echo '<p style="color:red;">Error: ' . esc_html($services->get_error_message()) . '</p>';
                } else {
                    ?>
                    <h3>Select Render Services to use for build triggers:</h3>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('gtrb_save_selected_services_nonce'); ?>
                        <input type="hidden" name="action" value="gtrb_save_selected_services" />
                        <ul style="list-style:none;padding-left:0;">
                            <?php foreach ($services as $service): 
                                $checked = in_array($service->id, $selected_services) ? 'checked' : '';
                            ?>
                                <li>
                                    <label>
                                        <input type="checkbox" name="selected_services[]" value="<?php echo esc_attr($service->id); ?>" <?php echo $checked; ?> />
                                        <?php echo esc_html($service->name); ?>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <input type="submit" class="button button-primary" value="Save Selected Services" />
                    </form>
                    <?php
                }
                ?>
            <?php endif; ?>

        </div>
        <?php
    }

    public function save_selected_services() {
        check_admin_referer('gtrb_save_selected_services_nonce');
        if (isset($_POST['selected_services']) && is_array($_POST['selected_services'])) {
            $selected = array_map('sanitize_text_field', $_POST['selected_services']);
            update_option('gtrb_selected_render_services', $selected);
        } else {
            update_option('gtrb_selected_render_services', []);
        }
        wp_redirect(admin_url('admin.php?page=gtrb-settings'));
        exit;
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
            wp_die('Invalid OAuth request.');
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
            wp_die('Error fetching token.');
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            wp_die('GitHub error: ' . esc_html($body['error_description']));
        }

        if (!isset($body['access_token'])) {
            wp_die('No access token received.');
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
            return new WP_Error('invalid_response', 'Invalid API response.');
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
            return new WP_Error('invalid_response', 'Invalid API response from Render.');
        }
        return $services;
    }
}

$gtrb_plugin = new GTRB_Plugin();
add_action('admin_init', [$gtrb_plugin, 'handle_github_oauth_callback']);
