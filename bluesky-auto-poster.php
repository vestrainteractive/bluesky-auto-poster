<?php
/*
Plugin Name: Bluesky Cross-Post Plugin
Plugin URI: https://github.com/vestrainteractive/bluesky-auto-poster
Description: Cross-posts WordPress posts to Bluesky
Version: 1.0.0
Author: Vestra Interactive
Author URI: https://vestrainteractive.com
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class BlueskyCrossPoster {

    private $option_name = 'bluesky_crossposter_options';

    public function __construct() {
        // Register settings and define the fields
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('save_post', [$this, 'handle_post_publish'], 10, 3);
        add_action('rest_api_init', [$this, 'register_cron_endpoint']);
        add_action('wp_ajax_bluesky_manual_post', [$this, 'manual_post_to_bluesky']);

        // Enqueue Bluesky logo
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    // Register settings with WordPress
    public function register_settings() {
        register_setting($this->option_name, $this->option_name, [
            'default' => [
                'profile_id' => '',
                'app_password' => '',
                'disable_wp_cron' => 0,
            ]
        ]);

        add_settings_section(
            'bluesky_crossposter_section',
            'Bluesky API Credentials',
            null,
            $this->option_name
        );

        add_settings_field(
            'profile_id',
            'Profile ID',
            [$this, 'render_profile_id_field'],
            $this->option_name,
            'bluesky_crossposter_section'
        );

        add_settings_field(
            'app_password',
            'App Password',
            [$this, 'render_app_password_field'],
            $this->option_name,
            'bluesky_crossposter_section'
        );

        add_settings_field(
            'disable_wp_cron',
            'Disable WP-Cron',
            [$this, 'render_disable_wp_cron_field'],
            $this->option_name,
            'bluesky_crossposter_section'
        );
    }

    // Render Profile ID field
    public function render_profile_id_field() {
        $options = get_option($this->option_name);
        ?>
        <input type="text" id="bluesky_profile_id" name="<?php echo $this->option_name; ?>[profile_id]" value="<?php echo esc_attr($options['profile_id'] ?? ''); ?>" class="regular-text" />
        <?php
    }

    // Render App Password field
    public function render_app_password_field() {
        $options = get_option($this->option_name);
        ?>
        <input type="password" id="bluesky_app_password" name="<?php echo $this->option_name; ?>[app_password]" value="<?php echo esc_attr($options['app_password'] ?? ''); ?>" class="regular-text" />
        <?php
    }

    // Render Disable WP-Cron field
    public function render_disable_wp_cron_field() {
        $options = get_option($this->option_name);
        ?>
        <input type="checkbox" id="disable_wp_cron" name="<?php echo $this->option_name; ?>[disable_wp_cron]" value="1" <?php checked($options['disable_wp_cron'], 1); ?> />
        <label for="disable_wp_cron">Disable WP-Cron</label>
        <?php
    }

    // Add Settings Page for the plugin
    public function add_settings_page() {
        add_options_page(
            'Bluesky Cross-Poster Settings',
            'Bluesky Cross-Poster',
            'manage_options',
            'bluesky-crossposter',
            [$this, 'render_settings_page']
        );
    }

    // Render settings page
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Bluesky Cross-Poster Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_name);
                do_settings_sections($this->option_name);
                ?>
                <?php submit_button(); ?>
            </form>
            <?php
            $options = get_option($this->option_name);
            if (!empty($options['disable_wp_cron'])) {
                echo '<p><strong>To enable cron-like functionality, set up a cron job to trigger the following URL:</strong></p>';
                echo '<pre>' . esc_url(home_url('/wp-json/bluesky-crossposter/v1/cron')) . '</pre>';
            }
            ?>
        </div>
        <?php
    }

    // Handle post publish event to automatically cross-post to Bluesky
    public function handle_post_publish($post_id, $post, $update) {
        // Only handle post publish, not updates
        if ($update || !isset($_POST['bluesky_crosspost'])) return;

        $options = get_option($this->option_name);

        if (empty($options['profile_id']) || empty($options['app_password'])) {
            return;
        }

        if (isset($_POST['bluesky_crosspost']) && $_POST['bluesky_crosspost'] === 'on') {
            $response = $this->post_to_bluesky($post);
            if ($response && !is_wp_error($response)) {
                update_post_meta($post_id, '_bluesky_url', $response['url']);
                add_action('admin_notices', function() use ($response) {
                    echo '<div class="notice notice-success"><p>Post successfully cross-posted to Bluesky: <a href="' . esc_url($response['url']) . '" target="_blank">View Post</a></p></div>';
                });
            }
        }
    }

    // Post to Bluesky using the Bluesky API
    private function post_to_bluesky($post) {
        $options = get_option($this->option_name);

        // Bluesky API URL - replace with actual API endpoint
        $url = 'https://api.bsky.app/v1/createPost'; // Example Bluesky endpoint
        $response = wp_remote_post($url, [
            'method' => 'POST',
            'body' => json_encode([
                'content' => $post->post_excerpt . "\n\n" . implode(', ', wp_get_post_tags($post->ID, ['fields' => 'names'])),
                'image_url' => get_the_post_thumbnail_url($post->ID, 'full'),
                'profile_id' => $options['profile_id'],
                'app_password' => $options['app_password'],
            ]),
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['url'])) {
            return $data;
        }

        return new WP_Error('bluesky_error', 'Failed to post to Bluesky');
    }

    // Register Cron Endpoint
    public function register_cron_endpoint() {
        register_rest_route('bluesky-crossposter/v1', '/cron', [
            'methods' => 'GET',
            'callback' => [$this, 'cron_callback'],
            'permission_callback' => '__return_true', // Adjust permissions as necessary
        ]);
    }

    // Cron Callback
    public function cron_callback() {
        // Perform background tasks if needed
    }

    // Enqueue scripts for Bluesky logo
    public function enqueue_scripts() {
        wp_enqueue_style('bluesky-crossposter-admin', plugin_dir_url(__FILE__) . 'assets/css/admin.css');
        wp_enqueue_script('bluesky-crossposter-admin', plugin_dir_url(__FILE__) . 'assets/js/admin.js', ['jquery'], false, true);
    }

    // Manual Post to Bluesky
    public function manual_post_to_bluesky() {
        if (!isset($_POST['post_id'])) {
            wp_send_json_error('No post ID specified');
        }

        $post_id = absint($_POST['post_id']);
        $post = get_post($post_id);

        if (!$post) {
            wp_send_json_error('Invalid post ID');
        }

        $response = $this->post_to_bluesky($post);
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        wp_send_json_success(['url' => $response['url']]);
    }
}

// Initialize the plugin
new BlueskyCrossPoster();
?>
