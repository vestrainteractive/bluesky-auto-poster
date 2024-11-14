<?php
/*
Plugin Name: Bluesky Cross-Post Plugin
Plugin URI: https://github.com/vestrainteractive/bluesky-auto-poster
Description: Cross-posts WordPress posts to Bluesky. 
Version: 1.0.0
Author: Vestra Interactive
Author URI: https://vestrainteractive.com
*/

if (!defined('ABSPATH')) exit;

class BlueskyCrossPoster {
    private $option_name = 'bluesky_crossposter_options';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('add_meta_boxes', [$this, 'add_bluesky_meta_box']);
        add_action('save_post', [$this, 'crosspost_to_bluesky_on_publish'], 10, 2);
        add_action('wp_ajax_manual_bluesky_post', [$this, 'manual_bluesky_post']);
    }

    // 1. Settings Page
    public function add_settings_page() {
        add_options_page(
            'Bluesky Cross-Poster Settings',
            'Bluesky Cross-Poster',
            'manage_options',
            'bluesky_crossposter',
            [$this, 'settings_page_html']
        );
    }

    public function settings_page_html() {
        ?>
        <div class="wrap">
            <h1>Bluesky Cross-Poster Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_name);
                $options = get_option($this->option_name);
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Bluesky Profile ID</th>
                        <td><input type="text" name="<?= $this->option_name ?>[profile_id]" value="<?= esc_attr($options['profile_id'] ?? '') ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">App Password</th>
                        <td><input type="password" name="<?= $this->option_name ?>[app_password]" value="<?= esc_attr($options['app_password'] ?? '') ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Disable WP-Cron for Posting</th>
                        <td>
                            <input type="checkbox" name="<?= $this->option_name ?>[disable_wp_cron]" value="1" <?= checked(1, $options['disable_wp_cron'] ?? 0, false) ?> />
                            <p>To enable cron functionality, use this URL: <?= esc_url(site_url('/wp-json/bluesky-crossposter/v1/cron')) ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function register_settings() {
        register_setting($this->option_name, $this->option_name);
    }

    // 2. Meta Box in Post Editor
    public function add_bluesky_meta_box() {
        add_meta_box('bluesky_crosspost', 'Bluesky Cross-Poster', [$this, 'render_bluesky_meta_box'], 'post', 'side', 'default');
    }

    public function render_bluesky_meta_box($post) {
        wp_nonce_field('bluesky_crossposter_meta_box', 'bluesky_crossposter_meta_box_nonce');
        $checked = get_post_meta($post->ID, '_bluesky_crosspost', true);
        $bluesky_url = get_post_meta($post->ID, '_bluesky_url', true);

        ?>
        <p><label><input type="checkbox" name="bluesky_crosspost" value="1" <?= checked($checked, 1) ?>> Cross-post to Bluesky</label></p>
        <p><button type="button" id="bluesky_manual_post" class="button button-secondary">Manual Post to Bluesky</button></p>
        <p id="bluesky_url" style="color: green;"><?= $bluesky_url ? 'Bluesky URL: ' . esc_url($bluesky_url) : ''; ?></p>
        <script>
            document.getElementById('bluesky_manual_post').addEventListener('click', function() {
                let data = {
                    action: 'manual_bluesky_post',
                    post_id: <?= $post->ID; ?>,
                    security: '<?= wp_create_nonce("bluesky_crossposter_meta_box"); ?>'
                };
                jQuery.post(ajaxurl, data, function(response) {
                    if (response.success) {
                        document.getElementById('bluesky_url').textContent = 'Bluesky URL: ' + response.data.url;
                    } else {
                        alert('Failed to post to Bluesky');
                    }
                });
            });
        </script>
        <?php
    }

    public function crosspost_to_bluesky_on_publish($post_id, $post) {
        if (!isset($_POST['bluesky_crossposter_meta_box_nonce']) || !wp_verify_nonce($_POST['bluesky_crossposter_meta_box_nonce'], 'bluesky_crossposter_meta_box')) return;
        if (get_post_meta($post_id, '_bluesky_url', true)) return; // Prevent re-posting

        $options = get_option($this->option_name);
        $should_post = isset($_POST['bluesky_crosspost']) && $_POST['bluesky_crosspost'] == 1;
        
        if ($should_post && 'publish' === $post->post_status) {
            $response = $this->post_to_bluesky($post);
            if ($response && !is_wp_error($response)) {
                $url = $response['url'];
                update_post_meta($post_id, '_bluesky_url', $url);
                echo "<script>document.getElementById('bluesky_url').textContent = 'Bluesky URL: $url';</script>";
            }
        }
    }

    // 3. Manual Post Function
    public function manual_bluesky_post() {
        check_ajax_referer('bluesky_crossposter_meta_box', 'security');
        $post_id = $_POST['post_id'];
        $post = get_post($post_id);

        if ($post && current_user_can('edit_post', $post_id)) {
            $response = $this->post_to_bluesky($post);
            if ($response && !is_wp_error($response)) {
                $url = $response['url'];
                update_post_meta($post_id, '_bluesky_url', $url);
                wp_send_json_success(['url' => $url]);
            }
        }

        wp_send_json_error(['message' => 'Failed to post to Bluesky']);
    }

    private function post_to_bluesky($post) {
        $options = get_option($this->option_name);
        $excerpt = $post->post_excerpt ?: wp_trim_words($post->post_content, 40);
        $featured_image = get_the_post_thumbnail_url($post->ID, 'full');

        $content = $excerpt . "\n\n" . implode(', ', wp_get_post_tags($post->ID, ['fields' => 'names']));

        $response = wp_remote_post('https://api.bluesky.com/posts', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($options['profile_id'] . ':' . $options['app_password']),
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'content' => $content,
                'image' => $featured_image,
            ]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['url'])) {
            return ['url' => $body['url']];
        }

        return new WP_Error('bluesky_error', 'Failed to retrieve URL from Bluesky');
    }
}

new BlueskyCrossPoster();
