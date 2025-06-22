<?php
/*
Plugin Name: GitHub to Render Builder
Description: GitHub OAuth + Render.com Integration, auswählbare Repos, Services, Build Trigger & Static Site Service Creator.
Version: 1.0
Author: Sven Hajer
*/

if (!defined('ABSPATH')) {
    exit;
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
        add_action('admin_post_gtrb_save_selected_repos', [$this, 'save_selected_repos']);
        add_action('admin_post_gtrb_trigger_builds', [$this, 'handle_trigger_builds']);
        add_action('admin_post_gtrb_create_static_site', [$this, 'handle_create_static_site']);
        add_action('admin_init', [$this, 'handle_github_oauth_callback']);

        add_shortcode('render_site', [$this, 'render_iframe_shortcode']);
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
        $selected_repos = get_option('gtrb_selected_github_repos', []);
        $client_id = get_option('gtrb_github_client_id');
        $client_secret = get_option('gtrb_github_client_secret');
        $last_service = get_option('gtrb_last_created_service');

        ?>
        <div class="wrap">
            <h1>GitHub & Render.com Integration</h1>

            <?php
            if (isset($_GET['build_triggered'])) {
                echo '<div class="notice notice-success is-dismissible"><p>Builds successfully triggered for selected Render services.</p></div>';
            }
            if (isset($_GET['static_site_created'])) {
                if ($last_service && !is_wp_error($last_service)) {
                    echo '<div class="notice notice-success is-dismissible"><p>Static Site Service created successfully! Service name: ' . esc_html($last_service['name']) . '</p></div>';
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p>Error creating Static Site Service.</p></div>';
                }
            }
            ?>

            <!-- GitHub OAuth Settings -->
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

            <!-- GitHub Login & Repo Selection -->
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
                        ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('gtrb_save_selected_repos_nonce'); ?>
                            <input type="hidden" name="action" value="gtrb_save_selected_repos" />
                            <h3>Select GitHub Repositories to build:</h3>
                            <ul style="list-style:none;padding-left:0;">
                                <?php foreach ($repos as $repo):
                                    $checked = in_array($repo->full_name, $selected_repos) ? 'checked' : '';
                                ?>
                                <li>
                                    <label>
                                        <input type="checkbox" name="selected_repos[]" value="<?php echo esc_attr($repo->full_name); ?>" <?php echo $checked; ?> />
                                        <?php echo esc_html($repo->full_name); ?>
                                    </label>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <input type="submit" class="button button-primary" value="Save Selected Repositories" />
                        </form>
                        <?php
                    }
                    ?>
                <?php endif; ?>
            <?php endif; ?>

            <hr>

            <!-- Render API Key & Services -->
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
                                        <?php echo esc_html($service->name); ?> — 
                                        <a href="<?php echo esc_url($service->url); ?>" target="_blank" rel="noopener noreferrer">
                                            <?php echo esc_html($service->url); ?>
                                        </a>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <input type="submit" class="button button-primary" value="Save Selected Services" />
                    </form>

                    <!-- Build trigger button -->
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:2em;">
                        <?php wp_nonce_field('gtrb_trigger_builds_nonce'); ?>
                        <input type="hidden" name="action" value="gtrb_trigger_builds" />
                        <input type="submit" class="button button-primary" value="Trigger Builds for Selected Services" />
                    </form>
                    <?php
                }
                ?>
            <?php endif; ?>

            <hr>

            <!-- New: Create Static Site Service -->
            <h2>3. Create a New Static Site Service on Render.com</h2>
            <?php if (!$render_api_key): ?>
                <p style="color:red;">Please save your Render API Key above to create a new service.</p>
            <?php else: ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('gtrb_create_static_site_nonce'); ?>
                    <input type="hidden" name="action" value="gtrb_create_static_site" />
                    <table class="form-table">
                        <tr>
                            <th><label for="site_name">Site Name (unique)</label></th>
                            <td><input type="text" name="site_name" id="site_name" required class="regular-text" placeholder="e.g. my-static-site" /></td>
                        </tr>
                        <tr>
                            <th><label for="repo_url">GitHub Repo URL</label></th>
                            <td><input type="url" name="repo_url" id="repo_url" required class="regular-text" placeholder="https://github.com/user/repo" /></td>
                        </tr>
                        <tr>
                            <th><label for="branch">Branch</label></th>
                            <td><input type="text" name="branch" id="branch" value="main" required class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th><label for="build_command">Build Command</label></th>
                            <td><input type="text" name="build_command" id="build_command" class="regular-text" placeholder="e.g. npm run build" /></td>
                        </tr>
                        <tr>
                            <th><label for="publish_directory">Publish Directory</label></th>
                            <td><input type="text" name="publish_directory" id="publish_directory" required class="regular-text" placeholder="e.g. build" /></td>
                        </tr>
                    </table>
                    <input type="submit" class="button button-primary" value="Create Static Site Service" />
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_create_static_site() {
        check_admin_referer('gtrb_create_static_site_nonce');

        $api_key = get_option($this->render_api_key_option);
        if (!$api_key) {
            wp_die('Render API Key missing.');
        }

        $site_name = sanitize_text_field($_POST['site_name'] ?? '');
        $repo_url = esc_url_raw($_POST['repo_url'] ?? '');
        $branch = sanitize_text_field($_POST['branch'] ?? 'main');
        $build_command = sanitize_text_field($_POST['build_command'] ?? '');
        $publish_directory = sanitize_text_field($_POST['publish_directory'] ?? '');

        if (empty($site_name) || empty($repo_url) || empty($branch) || empty($publish_directory)) {
            wp_die('Please fill in all required fields.');
        }

        $result = $this->create_render_static_site_service($api_key, $site_name, $repo_url, $branch, $build_command, $publish_directory);

        if (is_wp_error($result)) {
            wp_die('Error creating static site service: ' . $result->get_error_message());
        }

        update_option('gtrb_last_created_service', $result);

        wp_redirect(admin_url('admin.php?page=gtrb-settings&static_site_created=1'));
        exit;
    }

    public function create_render_static_site_service($api_key, $name, $repo, $branch = 'main', $build_command = '', $publish_directory = '') {
        $url = 'https://api.render.com/v1/services';

        $body = [
            'type' => 'static_site',
            'name' => $name,
            'repo' => $repo,
            'branch' => $branch,
            'buildCommand' => $build_command,
            'publishDirectory' => $publish_directory,
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 201) {
            return new WP_Error('render_api_error', 'Failed to create service: ' . ($response_body['message'] ?? 'Unknown error'));
        }

        return $response_body;
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

    public function save_selected_repos() {
        check_admin_referer('gtrb_save_selected_repos_nonce');

        if (isset($_POST['selected_repos']) && is_array($_POST['selected_repos'])) {
            $selected = array_map('sanitize_text_field', $_POST['selected_repos']);
            update_option('gtrb_selected_github_repos', $selected);
        } else {
            update_option('gtrb_selected_github_repos', []);
        }

        wp_redirect(admin_url('admin.php?page=gtrb-settings'));
        exit;
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

    public function trigger_builds_for_selected_services() {
        $api_key = get_option($this->render_api_key_option);
        $selected_services = get_option('gtrb_selected_render_services', []);

        if (!$api_key || empty($selected_services)) {
            return new WP_Error('missing_data', 'Render API key or selected services missing.');
        }

        foreach ($selected_services as $service_id) {
            $response = wp_remote_post("https://api.render.com/v1/services/{$service_id}/deploys", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([]),
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 201) { // 201 Created = success
                return new WP_Error('render_api_error', 'Build trigger failed for service ' . $service_id);
            }
        }

        return true;
    }

    public function handle_trigger_builds() {
        check_admin_referer('gtrb_trigger_builds_nonce');

        $result = $this->trigger_builds_for_selected_services();

        if (is_wp_error($result)) {
            wp_die('Error triggering builds: ' . $result->get_error_message());
        }

        wp_redirect(admin_url('admin.php?page=gtrb-settings&build_triggered=1'));
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

    public function render_iframe_shortcode($atts) {
        $atts = shortcode_atts([
            'service_url' => '',
            'width' => '100%',
            'height' => '800px',
        ], $atts, 'render_site');

        if (empty($atts['service_url'])) {
            return 'Render service URL not specified.';
        }

        return sprintf(
            '<iframe src="%s" width="%s" height="%s" style="border:none;"></iframe>',
            esc_url($atts['service_url']),
            esc_attr($atts['width']),
            esc_attr($atts['height'])
        );
    }
}

new GTRB_Plugin();
add_action('admin_init', [new GTRB_Plugin(), 'handle_github_oauth_callback']);
