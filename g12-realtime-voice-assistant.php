<?php
/**
 * Plugin Name: G12 Realtime Voice Assistant
 * Description: Bottom-center OpenAI Realtime voice concierge for G12 business setup guidance, page help, form assistance, and lead capture.
 * Version: 0.3.0
 * Author: G12
 */

if (!defined('ABSPATH')) {
    exit;
}

final class G12_Realtime_Voice_Assistant {
    const OPTION = 'g12_rva_settings';
    const REST_NAMESPACE = 'g12-rva/v1';
    const VERSION = '0.3.0';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'register_lead_post_type'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_footer', array($this, 'render_widget'));
        add_action('rest_api_init', array($this, 'register_routes'));
        add_action('admin_menu', array($this, 'register_admin_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public static function activate() {
        $defaults = self::defaults();
        $existing = get_option(self::OPTION, array());
        update_option(self::OPTION, array_merge($defaults, is_array($existing) ? $existing : array()), false);
    }

    public static function defaults() {
        return array(
            'enabled' => 1,
            'model' => 'gpt-realtime-2',
            'voice' => 'marin',
            'primary_form_id' => 1,
            'lead_email' => get_option('admin_email'),
            'brand_label' => 'G12 Voice Guide',
            'greeting' => 'Hi, I am your G12 voice guide. I can help with Dubai business setup, find the right page, and collect callback details one question at a time.',
            'store_sessions' => 1,
            'connection_mode' => 'ephemeral',
        );
    }

    private function settings() {
        $settings = get_option(self::OPTION, array());
        return array_merge(self::defaults(), is_array($settings) ? $settings : array());
    }

    private function api_key() {
        $settings = $this->settings();
        if (!empty($settings['api_key'])) {
            return trim((string) $settings['api_key']);
        }
        if (defined('G12_OPENAI_API_KEY') && G12_OPENAI_API_KEY) {
            return trim((string) G12_OPENAI_API_KEY);
        }
        $env_g12 = getenv('G12_OPENAI_API_KEY');
        if ($env_g12) {
            return trim((string) $env_g12);
        }
        if (defined('OPENAI_API_KEY') && OPENAI_API_KEY) {
            return trim((string) OPENAI_API_KEY);
        }
        $env = getenv('OPENAI_API_KEY');
        return $env ? trim((string) $env) : '';
    }

    public function register_lead_post_type() {
        register_post_type('g12_voice_lead', array(
            'labels' => array(
                'name' => 'Voice Leads',
                'singular_name' => 'Voice Lead',
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'g12-rva',
            'supports' => array('title', 'editor', 'custom-fields'),
            'capability_type' => 'post',
        ));

        register_post_type('g12_voice_session', array(
            'labels' => array(
                'name' => 'Voice Sessions',
                'singular_name' => 'Voice Session',
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'g12-rva',
            'supports' => array('title', 'editor', 'custom-fields'),
            'capability_type' => 'post',
        ));
    }

    public function enqueue_assets() {
        $settings = $this->settings();
        if (empty($settings['enabled']) || is_admin()) {
            return;
        }

        $base_url = plugin_dir_url(__FILE__);
        wp_enqueue_style('g12-rva', $base_url . 'assets/css/g12-rva.css', array(), self::VERSION);
        wp_enqueue_script('g12-rva', $base_url . 'assets/js/g12-rva.js', array(), self::VERSION, true);
        wp_localize_script('g12-rva', 'g12RvaConfig', array(
            'restBase' => esc_url_raw(rest_url(self::REST_NAMESPACE)),
            'nonce' => wp_create_nonce('wp_rest'),
            'brandLabel' => sanitize_text_field($settings['brand_label']),
            'greeting' => sanitize_text_field($settings['greeting']),
            'hasKey' => $this->api_key() !== '',
            'homeUrl' => home_url('/'),
            'storeSessions' => !empty($settings['store_sessions']),
            'connectionMode' => $settings['connection_mode'] === 'server' ? 'server' : 'ephemeral',
        ));
    }

    public function render_widget() {
        $settings = $this->settings();
        if (empty($settings['enabled']) || is_admin()) {
            return;
        }
        ?>
        <div class="g12-rva" data-g12-rva>
            <button class="g12-rva__orb" type="button" aria-label="Open G12 voice assistant" data-g12-rva-toggle>
                <span class="g12-rva__rings" aria-hidden="true"></span>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 14a3 3 0 0 0 3-3V6a3 3 0 1 0-6 0v5a3 3 0 0 0 3 3Z"></path>
                    <path d="M19 11a7 7 0 0 1-14 0"></path>
                    <path d="M12 18v4"></path>
                    <path d="M8 22h8"></path>
                </svg>
            </button>
            <section class="g12-rva__panel" aria-live="polite" hidden data-g12-rva-panel>
                <div class="g12-rva__header">
                    <div>
                        <strong><?php echo esc_html($settings['brand_label']); ?></strong>
                        <span data-g12-rva-status>Ready</span>
                    </div>
                    <button type="button" aria-label="Close voice assistant" data-g12-rva-close>&times;</button>
                </div>
                <div class="g12-rva__body">
                    <p data-g12-rva-message><?php echo esc_html($settings['greeting']); ?></p>
                    <div class="g12-rva__actions">
                        <button type="button" data-g12-rva-start>Start voice</button>
                        <button type="button" data-g12-rva-stop disabled>Stop</button>
                    </div>
                    <div class="g12-rva__links" hidden data-g12-rva-links></div>
                    <div class="g12-rva__history" hidden data-g12-rva-history></div>
                    <form class="g12-rva__lead" hidden data-g12-rva-lead>
                        <p class="g12-rva__lead-step" data-g12-rva-lead-step>First, what is your name?</p>
                        <input type="text" name="name" placeholder="Name" autocomplete="name" data-g12-rva-field="name">
                        <input type="tel" name="phone" placeholder="Phone" autocomplete="tel" data-g12-rva-field="phone" hidden>
                        <input type="email" name="email" placeholder="Email" autocomplete="email" data-g12-rva-field="email" hidden>
                        <textarea name="message" rows="3" placeholder="Business activity or question" data-g12-rva-field="message" hidden></textarea>
                        <input type="text" name="preferred_time" placeholder="Preferred callback time" data-g12-rva-field="preferred_time" hidden>
                        <button type="submit">Next</button>
                    </form>
                </div>
            </section>
        </div>
        <?php
    }

    public function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/client-secret', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_client_secret'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(self::REST_NAMESPACE, '/connect', array(
            'methods' => 'POST',
            'callback' => array($this, 'connect_realtime'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(self::REST_NAMESPACE, '/search', array(
            'methods' => 'POST',
            'callback' => array($this, 'search_site'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(self::REST_NAMESPACE, '/lead', array(
            'methods' => 'POST',
            'callback' => array($this, 'capture_lead'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(self::REST_NAMESPACE, '/session-log', array(
            'methods' => 'POST',
            'callback' => array($this, 'store_session_log'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(self::REST_NAMESPACE, '/admin/page-draft', array(
            'methods' => 'POST',
            'callback' => array($this, 'admin_page_draft'),
            'permission_callback' => function () {
                return current_user_can('edit_pages');
            },
        ));
    }

    public function connect_realtime(WP_REST_Request $request) {
        if (!$this->rate_limit('connect', 20, MINUTE_IN_SECONDS)) {
            return new WP_Error('g12_rva_rate_limited', 'Please wait before starting another voice session.', array('status' => 429));
        }

        $api_key = $this->api_key();
        if ($api_key === '') {
            return new WP_Error('g12_rva_missing_key', 'OpenAI API key is not configured.', array('status' => 500));
        }

        $sdp = (string) $request->get_param('sdp');
        if ($sdp === '') {
            $body = json_decode($request->get_body(), true);
            if (is_array($body) && isset($body['sdp'])) {
                $sdp = (string) $body['sdp'];
            }
        }
        if ($sdp === '' || strpos($sdp, 'v=0') !== 0) {
            return new WP_Error('g12_rva_bad_sdp', 'Invalid WebRTC offer.', array('status' => 400));
        }

        $settings = $this->settings();
        $session = array(
            'type' => 'realtime',
            'model' => sanitize_text_field($settings['model']),
            'instructions' => $this->instructions(),
            'audio' => array(
                'output' => array(
                    'voice' => sanitize_text_field($settings['voice']),
                ),
            ),
            'tools' => $this->tool_schema(),
        );

        $boundary = 'g12rva-' . wp_generate_password(24, false, false);
        $body = $this->multipart_body($boundary, array(
            'sdp' => $sdp,
            'session' => wp_json_encode($session),
        ));

        $response = wp_remote_post('https://api.openai.com/v1/realtime/calls', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                'OpenAI-Safety-Identifier' => $this->safety_identifier(),
            ),
            'body' => $body,
            'timeout' => 45,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('g12_rva_openai_request', $response->get_error_message(), array('status' => 500));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $answer = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300 || strpos($answer, 'v=0') !== 0) {
            return new WP_Error('g12_rva_openai_error', 'Could not create Realtime connection.', array('status' => 500));
        }

        return rest_ensure_response(array('sdp' => $answer));
    }

    private function multipart_body($boundary, $fields) {
        $eol = "\r\n";
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . $eol;
            $body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
            $body .= (string) $value . $eol;
        }
        $body .= '--' . $boundary . '--' . $eol;
        return $body;
    }

    private function rate_limit($bucket, $limit, $window) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $key = 'g12_rva_rl_' . md5($bucket . '|' . $ip);
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            return false;
        }
        set_transient($key, $count + 1, $window);
        return true;
    }

    public function create_client_secret(WP_REST_Request $request) {
        if (!$this->rate_limit('client-secret', 20, MINUTE_IN_SECONDS)) {
            return new WP_Error('g12_rva_rate_limited', 'Please wait before starting another voice session.', array('status' => 429));
        }

        $api_key = $this->api_key();
        if ($api_key === '') {
            return new WP_Error('g12_rva_missing_key', 'OpenAI API key is not configured.', array('status' => 500));
        }

        $settings = $this->settings();
        $session = array(
            'session' => array(
                'type' => 'realtime',
                'model' => sanitize_text_field($settings['model']),
                'instructions' => $this->instructions(),
                'audio' => array(
                    'output' => array(
                        'voice' => sanitize_text_field($settings['voice']),
                    ),
                ),
                'tools' => $this->tool_schema(),
            ),
        );

        $response = wp_remote_post('https://api.openai.com/v1/realtime/client_secrets', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'OpenAI-Safety-Identifier' => $this->safety_identifier(),
            ),
            'body' => wp_json_encode($session),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('g12_rva_openai_request', $response->get_error_message(), array('status' => 500));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($body)) {
            return new WP_Error('g12_rva_openai_error', 'Could not create Realtime client secret.', array('status' => 500));
        }

        return rest_ensure_response($body);
    }

    private function instructions() {
        return "You are the G12 voice concierge for a WordPress website about UAE and Dubai business setup. Speak briefly and naturally. Help users choose mainland, free zone, offshore, visa, tax, banking, and license options. If a user wants a callback or form help, ask exactly one question at a time in this order: name, phone number, email, business activity or message, preferred callback time, then confirmation. Never ask for all form fields in one message. Use any session context provided by the browser so the user does not need to repeat details after a page refresh. Use tools when helpful: site_search to find relevant website pages, open_page to open relevant same-site pages in a new tab, fill_contact_form to fill visible forms with details already collected, and request_callback only once after the user clearly confirms they want contact. If a request_callback tool returns duplicate=true or alreadySent=true, do not call it again; tell the user the request is already saved. Never claim legal certainty. Do not edit WordPress pages for public users. For page changes, say an admin must approve changes.";
    }

    private function tool_schema() {
        return array(
            array(
                'type' => 'function',
                'name' => 'site_search',
                'description' => 'Search G12 website pages and posts for a user business setup question.',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'query' => array('type' => 'string', 'description' => 'Search phrase.'),
                    ),
                    'required' => array('query'),
                ),
            ),
            array(
                'type' => 'function',
                'name' => 'open_page',
                'description' => 'Open a same-site page for the visitor.',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'url' => array('type' => 'string', 'description' => 'Same-site URL to open.'),
                    ),
                    'required' => array('url'),
                ),
            ),
            array(
                'type' => 'function',
                'name' => 'fill_contact_form',
                'description' => 'Fill visible contact form fields on the current page. The user must still submit unless they confirm request_callback.',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'name' => array('type' => 'string'),
                        'phone' => array('type' => 'string'),
                        'email' => array('type' => 'string'),
                        'message' => array('type' => 'string'),
                        'preferred_time' => array('type' => 'string'),
                    ),
                ),
            ),
            array(
                'type' => 'function',
                'name' => 'request_callback',
                'description' => 'Create a callback lead after the user clearly confirms.',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'name' => array('type' => 'string'),
                        'phone' => array('type' => 'string'),
                        'email' => array('type' => 'string'),
                        'message' => array('type' => 'string'),
                        'preferred_time' => array('type' => 'string'),
                    ),
                    'required' => array('name', 'phone', 'email', 'message'),
                ),
            ),
        );
    }

    private function safety_identifier() {
        $raw = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        $salt = defined('AUTH_SALT') ? AUTH_SALT : wp_salt('auth');
        return hash('sha256', $raw . '|' . $salt);
    }

    public function search_site(WP_REST_Request $request) {
        if (!$this->rate_limit('search', 60, MINUTE_IN_SECONDS)) {
            return new WP_Error('g12_rva_rate_limited', 'Too many searches. Please wait.', array('status' => 429));
        }

        $query = sanitize_text_field((string) $request->get_param('query'));
        if ($query === '') {
            return rest_ensure_response(array('results' => array()));
        }

        $wp_query = new WP_Query(array(
            'post_type' => array('page', 'post', 'lp'),
            'post_status' => 'publish',
            's' => $query,
            'posts_per_page' => 5,
            'no_found_rows' => true,
        ));

        $results = array();
        foreach ($wp_query->posts as $post) {
            $results[] = array(
                'title' => get_the_title($post),
                'url' => get_permalink($post),
                'excerpt' => wp_trim_words(wp_strip_all_tags($post->post_content), 22),
            );
        }
        wp_reset_postdata();

        if (empty($results)) {
            $results = $this->fallback_links($query);
        }

        return rest_ensure_response(array('results' => $results));
    }

    private function fallback_links($query) {
        $home = home_url('/');
        $q = strtolower($query);
        $links = array();
        if (strpos($q, 'free') !== false || strpos($q, 'license') !== false || strpos($q, 'cost') !== false) {
            $links[] = array('title' => 'Business Setup in Dubai Free Zone', 'url' => $home . 'business-setup-in-dubai-free-zone/', 'excerpt' => 'Free zone setup guidance and options.');
        }
        if (strpos($q, 'mainland') !== false || strpos($q, 'dubai') !== false) {
            $links[] = array('title' => 'Business Setup in Dubai Mainland', 'url' => $home . 'business-setup-in-dubai-mainland/', 'excerpt' => 'Mainland company formation guidance.');
        }
        if (strpos($q, 'visa') !== false || strpos($q, 'golden') !== false) {
            $links[] = array('title' => 'Golden Visa UAE', 'url' => $home . 'golden-visa/', 'excerpt' => 'UAE Golden Visa support.');
        }
        if (empty($links)) {
            $links[] = array('title' => 'G12 Home', 'url' => $home, 'excerpt' => 'Start from the main G12 website.');
        }
        return array_slice($links, 0, 5);
    }

    public function capture_lead(WP_REST_Request $request) {
        if (!$this->rate_limit('lead', 8, HOUR_IN_SECONDS)) {
            return new WP_Error('g12_rva_rate_limited', 'Too many requests. Please wait.', array('status' => 429));
        }

        $name = sanitize_text_field((string) $request->get_param('name'));
        $phone = sanitize_text_field((string) $request->get_param('phone'));
        $email = sanitize_email((string) $request->get_param('email'));
        $message = sanitize_textarea_field((string) $request->get_param('message'));
        $preferred_time = sanitize_text_field((string) $request->get_param('preferred_time'));
        $page = esc_url_raw((string) $request->get_param('page'));

        if ($phone === '' && $email === '') {
            return new WP_Error('g12_rva_missing_contact', 'Please provide phone or email.', array('status' => 400));
        }

        $lead_hash = $this->lead_hash($name, $phone, $email, $message, $preferred_time, $page);
        $transient_key = 'g12_rva_lead_' . $lead_hash;
        $existing_id = (int) get_transient($transient_key);
        if (!$existing_id) {
            $existing = get_posts(array(
                'post_type' => 'g12_voice_lead',
                'post_status' => array('private', 'publish', 'draft'),
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_key' => '_g12_rva_lead_hash',
                'meta_value' => $lead_hash,
                'date_query' => array(
                    array(
                        'after' => '2 hours ago',
                    ),
                ),
            ));
            if (!empty($existing)) {
                $existing_id = (int) $existing[0];
            }
        }

        if ($existing_id) {
            set_transient($transient_key, $existing_id, 2 * HOUR_IN_SECONDS);
            return rest_ensure_response(array(
                'ok' => true,
                'duplicate' => true,
                'leadId' => $existing_id,
                'message' => 'This callback request is already saved.',
            ));
        }

        $title = $name !== '' ? $name : ($phone !== '' ? $phone : $email);
        $body = "Voice assistant lead\n\nName: {$name}\nPhone: {$phone}\nEmail: {$email}\nMessage: {$message}\nPage: {$page}\n";
        if ($preferred_time !== '') {
            $body .= "Preferred time: {$preferred_time}\n";
        }
        $post_id = wp_insert_post(array(
            'post_type' => 'g12_voice_lead',
            'post_status' => 'private',
            'post_title' => 'Voice lead - ' . $title,
            'post_content' => $body,
        ), true);

        if (!is_wp_error($post_id) && $post_id) {
            update_post_meta($post_id, '_g12_rva_lead_hash', $lead_hash);
            set_transient($transient_key, (int) $post_id, 2 * HOUR_IN_SECONDS);
        }

        $settings = $this->settings();
        $to = sanitize_email($settings['lead_email']);
        if ($to === '') {
            $to = get_option('admin_email');
        }
        if ($to) {
            wp_mail($to, '[G12] Voice assistant callback request', $body, array('Content-Type: text/plain; charset=UTF-8'));
        }

        return rest_ensure_response(array(
            'ok' => true,
            'leadId' => is_wp_error($post_id) ? 0 : (int) $post_id,
        ));
    }

    public function store_session_log(WP_REST_Request $request) {
        $settings = $this->settings();
        if (empty($settings['store_sessions'])) {
            return rest_ensure_response(array('ok' => true, 'stored' => false));
        }
        if (!$this->rate_limit('session-log', 20, HOUR_IN_SECONDS)) {
            return new WP_Error('g12_rva_rate_limited', 'Too many session logs. Please wait.', array('status' => 429));
        }

        $messages = $request->get_param('messages');
        $lead = $request->get_param('lead');
        $page = esc_url_raw((string) $request->get_param('page'));
        if (!is_array($messages)) {
            $messages = array();
        }
        if (!is_array($lead)) {
            $lead = array();
        }

        $clean_messages = array();
        foreach (array_slice($messages, -30) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = sanitize_key($message['role'] ?? '');
            $text = sanitize_textarea_field((string) ($message['text'] ?? ''));
            if ($text === '' || ($role !== 'user' && $role !== 'assistant')) {
                continue;
            }
            $clean_messages[] = ucfirst($role) . ': ' . $text;
        }

        $clean_lead = array();
        foreach (array('name', 'phone', 'email', 'message', 'preferred_time') as $key) {
            if (!empty($lead[$key])) {
                $clean_lead[$key] = sanitize_text_field((string) $lead[$key]);
            }
        }

        if (empty($clean_messages) && empty($clean_lead)) {
            return rest_ensure_response(array('ok' => true, 'stored' => false));
        }

        $title_name = !empty($clean_lead['name']) ? $clean_lead['name'] : current_time('mysql');
        $body = "Voice session\n\nPage: {$page}\n\n";
        if (!empty($clean_lead)) {
            $body .= "Collected details:\n";
            foreach ($clean_lead as $key => $value) {
                $body .= ucwords(str_replace('_', ' ', $key)) . ': ' . $value . "\n";
            }
            $body .= "\n";
        }
        if (!empty($clean_messages)) {
            $body .= "Conversation:\n" . implode("\n", $clean_messages);
        }

        $post_id = wp_insert_post(array(
            'post_type' => 'g12_voice_session',
            'post_status' => 'private',
            'post_title' => 'Voice session - ' . $title_name,
            'post_content' => $body,
        ), true);

        return rest_ensure_response(array(
            'ok' => true,
            'stored' => !is_wp_error($post_id),
            'sessionId' => is_wp_error($post_id) ? 0 : (int) $post_id,
        ));
    }

    private function lead_hash($name, $phone, $email, $message, $preferred_time, $page) {
        $parts = array(
            strtolower(trim((string) $name)),
            preg_replace('/\D+/', '', (string) $phone),
            strtolower(trim((string) $email)),
            strtolower(trim((string) $message)),
            strtolower(trim((string) $preferred_time)),
            strtok((string) $page, '?'),
        );
        return hash('sha256', implode('|', $parts));
    }

    public function admin_page_draft(WP_REST_Request $request) {
        $page_id = absint($request->get_param('page_id'));
        $instruction = sanitize_textarea_field((string) $request->get_param('instruction'));
        if (!$page_id || $instruction === '') {
            return new WP_Error('g12_rva_bad_admin_request', 'Page ID and instruction are required.', array('status' => 400));
        }
        return rest_ensure_response(array(
            'ok' => true,
            'message' => 'Admin-only page editing is intentionally approval-based. Draft changes should be reviewed in WordPress before publishing.',
            'pageId' => $page_id,
            'instruction' => $instruction,
        ));
    }

    public function register_admin_page() {
        add_menu_page(
            'G12 Voice Assistant',
            'G12 Voice Assistant',
            'manage_options',
            'g12-rva',
            array($this, 'render_admin_page'),
            'dashicons-microphone',
            58
        );
    }

    public function register_settings() {
        register_setting('g12_rva_settings_group', self::OPTION, array(
            'sanitize_callback' => array($this, 'sanitize_settings'),
        ));
    }

    public function sanitize_settings($input) {
        $defaults = self::defaults();
        $old = $this->settings();
        $out = array();
        $out['enabled'] = empty($input['enabled']) ? 0 : 1;
        $out['model'] = sanitize_text_field($input['model'] ?? $defaults['model']);
        $out['voice'] = sanitize_text_field($input['voice'] ?? $defaults['voice']);
        $out['primary_form_id'] = absint($input['primary_form_id'] ?? $defaults['primary_form_id']);
        $out['lead_email'] = sanitize_email($input['lead_email'] ?? $defaults['lead_email']);
        $out['brand_label'] = sanitize_text_field($input['brand_label'] ?? $defaults['brand_label']);
        $out['greeting'] = sanitize_text_field($input['greeting'] ?? $defaults['greeting']);
        $out['store_sessions'] = empty($input['store_sessions']) ? 0 : 1;
        $connection_mode = sanitize_key($input['connection_mode'] ?? $defaults['connection_mode']);
        $out['connection_mode'] = $connection_mode === 'server' ? 'server' : 'ephemeral';
        $new_key = trim((string) ($input['api_key'] ?? ''));
        $out['api_key'] = $new_key !== '' ? $new_key : ($old['api_key'] ?? '');
        return $out;
    }

    public function render_admin_page() {
        $settings = $this->settings();
        ?>
        <div class="wrap">
            <h1>G12 Realtime Voice Assistant</h1>
            <p>Server-side OpenAI key status: <strong><?php echo $this->api_key() !== '' ? 'configured' : 'missing'; ?></strong></p>
            <form method="post" action="options.php">
                <?php settings_fields('g12_rva_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Enabled</th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[enabled]" value="1" <?php checked(!empty($settings['enabled'])); ?>> Show assistant on frontend</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Realtime model</th>
                        <td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[model]" value="<?php echo esc_attr($settings['model']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Voice</th>
                        <td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[voice]" value="<?php echo esc_attr($settings['voice']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Lead email</th>
                        <td><input class="regular-text" type="email" name="<?php echo esc_attr(self::OPTION); ?>[lead_email]" value="<?php echo esc_attr($settings['lead_email']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Brand label</th>
                        <td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[brand_label]" value="<?php echo esc_attr($settings['brand_label']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Greeting</th>
                        <td><textarea class="large-text" rows="3" name="<?php echo esc_attr(self::OPTION); ?>[greeting]"><?php echo esc_textarea($settings['greeting']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row">Store voice sessions</th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[store_sessions]" value="1" <?php checked(!empty($settings['store_sessions'])); ?>> Save private session summaries in WordPress</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Connection mode</th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPTION); ?>[connection_mode]">
                                <option value="ephemeral" <?php selected($settings['connection_mode'], 'ephemeral'); ?>>Ephemeral token (verified stable)</option>
                                <option value="server" <?php selected($settings['connection_mode'], 'server'); ?>>Server-side SDP first, fallback to ephemeral</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Optional API key override</th>
                        <td><input class="regular-text" type="password" name="<?php echo esc_attr(self::OPTION); ?>[api_key]" value="" autocomplete="new-password"><p class="description">Leave blank to keep the existing server key.</p></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

register_activation_hook(__FILE__, array('G12_Realtime_Voice_Assistant', 'activate'));
G12_Realtime_Voice_Assistant::instance();
