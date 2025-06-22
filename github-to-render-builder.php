<?php
/*
Plugin Name: GitHub to Render Builder
Description: Verlinkt ein GitHub-Repo und baut es auf Render.com per API. Einfaches Build-Trigger-Plugin.
Version: 0.1
Author: Sven Hajer
*/

// Admin Menü hinzufügen
add_action('admin_menu', function() {
    add_options_page('GitHub Render Builder', 'GitHub Render Builder', 'manage_options', 'github-render-builder', 'gtrb_admin_page');
});

// Plugin-Einstellungen registrieren
add_action('admin_init', function() {
    register_setting('gtrb_settings', 'gtrb_github_repo');
    register_setting('gtrb_settings', 'gtrb_render_api_key');
    register_setting('gtrb_settings', 'gtrb_last_build_status');
});

// Admin Seite HTML
function gtrb_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Handle Build Trigger
    if (isset($_POST['gtrb_trigger_build'])) {
        check_admin_referer('gtrb_build_nonce');

        $repo = get_option('gtrb_github_repo');
        $api_key = get_option('gtrb_render_api_key');

        if (!$repo || !$api_key) {
            echo '<div class="notice notice-error"><p>Bitte GitHub Repo und Render API-Key eintragen!</p></div>';
        } else {
            $result = gtrb_trigger_render_build($repo, $api_key);
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p>Build fehlgeschlagen: ' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                update_option('gtrb_last_build_status', 'Build gestartet um '.date('Y-m-d H:i:s'));
                echo '<div class="notice notice-success"><p>Build erfolgreich gestartet!</p></div>';
            }
        }
    }

    // Formular
    ?>
    <div class="wrap">
        <h1>GitHub to Render Builder</h1>
        <form method="post" action="">
            <?php settings_fields('gtrb_settings'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">GitHub Repo URL</th>
                    <td><input type="text" name="gtrb_github_repo" value="<?php echo esc_attr(get_option('gtrb_github_repo')); ?>" class="regular-text" placeholder="https://github.com/user/repo" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Render API Key</th>
                    <td><input type="password" name="gtrb_render_api_key" value="<?php echo esc_attr(get_option('gtrb_render_api_key')); ?>" class="regular-text" /></td>
                </tr>
            </table>
            <?php submit_button('Speichern'); ?>
        </form>

        <h2>Build starten</h2>
        <form method="post" action="">
            <?php wp_nonce_field('gtrb_build_nonce'); ?>
            <input type="hidden" name="gtrb_trigger_build" value="1" />
            <?php submit_button('Build bei Render.com starten'); ?>
        </form>

        <h3>Letzter Build Status:</h3>
        <p><?php echo esc_html(get_option('gtrb_last_build_status', 'Noch kein Build gestartet.')); ?></p>
    </div>
    <?php
}

// Build via Render API triggern
function gtrb_trigger_render_build($repo, $api_key) {
    // Render Service ID aus Repo-URL ableiten oder konfigurieren
    // Hier als Beispiel statisch - muss angepasst werden!
    $service_id = 'dein-render-service-id';

    $url = "https://api.render.com/deploy/srv-$service_id/webhook";

    $response = wp_remote_post($url, [
        'headers' => [
            'Authorization' => "Bearer $api_key",
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode(['repo' => $repo]),
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200 && $code !== 201) {
        return new WP_Error('render_error', "Render API Fehler: HTTP $code");
    }

    return true;
}
