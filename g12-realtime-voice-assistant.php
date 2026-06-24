<?php
/**
 * Plugin Name: G12 Realtime Voice Assistant
 * Description: Bottom-center OpenAI Realtime voice concierge for G12 business setup guidance, page help, form assistance, and lead capture.
 * Version: 0.4.2
 * Author: G12
 */

if (!defined('ABSPATH')) {
    exit;
}

final class G12_Realtime_Voice_Assistant {
    const OPTION = 'g12_rva_settings';
    const REST_NAMESPACE = 'g12-rva/v1';
    const VERSION = '0.4.2';

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
            'scoring_model' => 'gpt-5.4-mini',
            'voice' => 'marin',
            'primary_form_id' => 1,
            'lead_email' => get_option('admin_email'),
            'brand_label' => 'G12 Voice Guide',
            'greeting' => 'Hi, I am your G12 voice guide. Tell me what kind of business you want to start, and I will guide you one step at a time.',
            'store_sessions' => 1,
            'connection_mode' => 'ephemeral',
            'lead_scoring' => 1,
            'multilingual' => 1,
            'qualification_depth' => 'smart',
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
            'multilingual' => !empty($settings['multilingual']),
            'qualificationDepth' => in_array($settings['qualification_depth'], array('short', 'smart', 'deep'), true) ? $settings['qualification_depth'] : 'smart',
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
                        <p class="g12-rva__lead-step" data-g12-rva-lead-step>First, what business activity do you want to start?</p>
                        <textarea name="message" rows="3" placeholder="Business activity or question" data-g12-rva-field="message"></textarea>
                        <input type="text" name="setup_location" placeholder="Mainland, free zone, offshore, or unsure" data-g12-rva-field="setup_location" hidden>
                        <input type="text" name="visa_need" placeholder="Do you need visas?" data-g12-rva-field="visa_need" hidden>
                        <input type="text" name="timeline" placeholder="When do you want to start?" data-g12-rva-field="timeline" hidden>
                        <input type="text" name="name" placeholder="Name" autocomplete="name" data-g12-rva-field="name" hidden>
                        <input type="tel" name="phone" placeholder="Phone" autocomplete="tel" data-g12-rva-field="phone" hidden>
                        <input type="email" name="email" placeholder="Email" autocomplete="email" data-g12-rva-field="email" hidden>
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
        $settings = $this->settings();
        $language_rule = !empty($settings['multilingual'])
            ? 'Detect the visitor language and reply in the same language when practical. If unsure, use simple English.'
            : 'Use simple English.';

        return "You are the G12 voice concierge for a WordPress website about UAE and Dubai business setup. Sound like a warm human consultant: calm, simple, helpful, and not pushy. {$language_rule} Your goal is lead quality, not just long answers. Understand the visitor mind by listening for intent, urgency, confusion, business activity, visa need, and timeline. Use a smart 5-step qualification flow: 1) business activity, 2) preferred setup type or location such as mainland/free zone/offshore/unsure, 3) visa need, 4) timeline or urgency, 5) contact details. Ask exactly one question at a time and adapt based on what the visitor already said. Never ask for all form fields in one message. For names and phone numbers, be strict: ask the visitor to spell the name if unclear, ask for phone digits one by one if needed, and prefer the visible form for final contact details. Use update_visitor_profile whenever you learn language, intent, urgency, service interest, setup location, visa need, or timeline. Use site_search when a page can help, open_page only for same-site pages in a new tab, fill_contact_form with details already collected, and request_callback only after you read back the exact name, phone or email, and business need, then the user confirms they are correct. When calling request_callback after confirmation, include confirmed_details=true. If request_callback says needsConfirmation=true, read back the exact details and ask for confirmation before calling again. If the user corrects submitted details, update the details and request_callback again with the corrected values. If request_callback returns duplicate=true or alreadySent=true, do not call it again; tell the user the request is already saved. Never claim legal certainty. Do not edit WordPress pages for public users. For page changes, say an admin must approve changes.";
    }

    private function tool_schema() {
        return array(
            array(
                'type' => 'function',
                'name' => 'update_visitor_profile',
                'description' => 'Update the remembered visitor profile when the assistant learns intent, language, urgency, service interest, setup location, visa need, or timeline. This does not submit a lead.',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'language' => array('type' => 'string'),
                        'intent' => array('type' => 'string'),
                        'urgency' => array('type' => 'string'),
                        'service_interest' => array('type' => 'string'),
                        'setup_location' => array('type' => 'string'),
                        'visa_need' => array('type' => 'string'),
                        'timeline' => array('type' => 'string'),
                        'confidence' => array('type' => 'string'),
                    ),
                ),
            ),
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
                        'language' => array('type' => 'string'),
                        'intent' => array('type' => 'string'),
                        'urgency' => array('type' => 'string'),
                        'service_interest' => array('type' => 'string'),
                        'setup_location' => array('type' => 'string'),
                        'visa_need' => array('type' => 'string'),
                        'timeline' => array('type' => 'string'),
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
                        'language' => array('type' => 'string'),
                        'intent' => array('type' => 'string'),
                        'urgency' => array('type' => 'string'),
                        'service_interest' => array('type' => 'string'),
                        'setup_location' => array('type' => 'string'),
                        'visa_need' => array('type' => 'string'),
                        'timeline' => array('type' => 'string'),
                        'confirmed_details' => array('type' => 'boolean', 'description' => 'True only after the visitor confirms the read-back name, phone or email, and business need are correct.'),
                    ),
                    'required' => array('message'),
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
        $raw_email = trim((string) $request->get_param('email'));
        $email = sanitize_email($raw_email);
        $message = sanitize_textarea_field((string) $request->get_param('message'));
        $preferred_time = sanitize_text_field((string) $request->get_param('preferred_time'));
        $language = sanitize_text_field((string) $request->get_param('language'));
        $intent = sanitize_text_field((string) $request->get_param('intent'));
        $urgency = sanitize_text_field((string) $request->get_param('urgency'));
        $service_interest = sanitize_text_field((string) $request->get_param('service_interest'));
        $setup_location = sanitize_text_field((string) $request->get_param('setup_location'));
        $visa_need = sanitize_text_field((string) $request->get_param('visa_need'));
        $timeline = sanitize_text_field((string) $request->get_param('timeline'));
        $page = esc_url_raw((string) $request->get_param('page'));
        $confirmed_details = rest_sanitize_boolean($request->get_param('confirmed_details'));

        if (!$confirmed_details) {
            return new WP_Error('g12_rva_unconfirmed_details', 'Please confirm the exact name, contact, and business need before sending.', array('status' => 400));
        }

        if ($phone === '' && $email === '') {
            return new WP_Error('g12_rva_missing_contact', 'Please provide phone or email.', array('status' => 400));
        }
        if ($phone !== '') {
            $digits = preg_replace('/\D+/', '', $phone);
            if (strlen($digits) < 7 || strlen($digits) > 15) {
                return new WP_Error('g12_rva_invalid_phone', 'Please provide a valid phone number.', array('status' => 400));
            }
        }
        if ($raw_email !== '' && $email === '') {
            return new WP_Error('g12_rva_invalid_email', 'Please provide a valid email address.', array('status' => 400));
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
        $lead_data = array(
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'message' => $message,
            'preferred_time' => $preferred_time,
            'language' => $language,
            'intent' => $intent,
            'urgency' => $urgency,
            'service_interest' => $service_interest,
            'setup_location' => $setup_location,
            'visa_need' => $visa_need,
            'timeline' => $timeline,
            'page' => $page,
        );
        $score = $this->score_lead($lead_data);
        $body = $this->lead_body($lead_data, $score);
        $post_id = wp_insert_post(array(
            'post_type' => 'g12_voice_lead',
            'post_status' => 'private',
            'post_title' => 'Voice lead - ' . $title,
            'post_content' => $body,
        ), true);

        if (!is_wp_error($post_id) && $post_id) {
            update_post_meta($post_id, '_g12_rva_lead_hash', $lead_hash);
            update_post_meta($post_id, '_g12_rva_lead_score', $score['lead_score']);
            update_post_meta($post_id, '_g12_rva_urgency', $score['urgency'] ?: $urgency);
            update_post_meta($post_id, '_g12_rva_intent', $score['intent'] ?: $intent);
            update_post_meta($post_id, '_g12_rva_service_match', $score['service_match'] ?: $service_interest);
            update_post_meta($post_id, '_g12_rva_recommended_action', $score['recommended_action']);
            update_post_meta($post_id, '_g12_rva_language', $score['language'] ?: $language);
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

    private function score_lead($lead_data) {
        $fallback = array(
            'lead_score' => 'unscored',
            'language' => $lead_data['language'] ?? '',
            'intent' => $lead_data['intent'] ?? '',
            'urgency' => $lead_data['urgency'] ?? '',
            'service_match' => $lead_data['service_interest'] ?? '',
            'recommended_action' => 'Review the lead and follow up manually.',
            'summary' => $this->fallback_summary($lead_data),
            'scoring_error' => '',
        );

        $settings = $this->settings();
        if (empty($settings['lead_scoring'])) {
            $fallback['scoring_error'] = 'disabled';
            return $fallback;
        }

        $api_key = $this->api_key();
        if ($api_key === '') {
            $fallback['scoring_error'] = 'missing_api_key';
            return $fallback;
        }

        $prompt = "Score this UAE business setup website lead for G12. Return only compact JSON with keys: lead_score (hot|warm|cold|unscored), language, intent, urgency, service_match, recommended_action, summary. Summary must be one short sales-ready paragraph. Recommended action must be practical for sales team.";
        $response = wp_remote_post('https://api.openai.com/v1/responses', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'OpenAI-Safety-Identifier' => $this->safety_identifier(),
            ),
            'body' => wp_json_encode(array(
                'model' => sanitize_text_field($settings['scoring_model']),
                'reasoning' => array('effort' => 'low'),
                'text' => array('verbosity' => 'low'),
                'max_output_tokens' => 500,
                'input' => array(
                    array('role' => 'system', 'content' => $prompt),
                    array('role' => 'user', 'content' => wp_json_encode($lead_data)),
                ),
            )),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            $fallback['scoring_error'] = $response->get_error_message();
            return $fallback;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            $fallback['scoring_error'] = 'api_error';
            return $fallback;
        }

        $text = $this->responses_text($data);
        $parsed = $this->decode_json_text($text);
        if (!is_array($parsed)) {
            $fallback['scoring_error'] = 'parse_error';
            return $fallback;
        }

        $score = array_merge($fallback, array(
            'lead_score' => $this->allowed_value($parsed['lead_score'] ?? '', array('hot', 'warm', 'cold', 'unscored'), 'unscored'),
            'language' => sanitize_text_field((string) ($parsed['language'] ?? $fallback['language'])),
            'intent' => sanitize_text_field((string) ($parsed['intent'] ?? $fallback['intent'])),
            'urgency' => sanitize_text_field((string) ($parsed['urgency'] ?? $fallback['urgency'])),
            'service_match' => sanitize_text_field((string) ($parsed['service_match'] ?? $fallback['service_match'])),
            'recommended_action' => sanitize_text_field((string) ($parsed['recommended_action'] ?? $fallback['recommended_action'])),
            'summary' => sanitize_textarea_field((string) ($parsed['summary'] ?? $fallback['summary'])),
            'scoring_error' => '',
        ));

        if ($score['summary'] === '') {
            $score['summary'] = $fallback['summary'];
        }
        if ($score['recommended_action'] === '') {
            $score['recommended_action'] = $fallback['recommended_action'];
        }

        return $score;
    }

    private function responses_text($data) {
        if (!empty($data['output_text']) && is_string($data['output_text'])) {
            return $data['output_text'];
        }
        if (empty($data['output']) || !is_array($data['output'])) {
            return '';
        }
        $text = '';
        foreach ($data['output'] as $item) {
            if (empty($item['content']) || !is_array($item['content'])) {
                continue;
            }
            foreach ($item['content'] as $content) {
                if (isset($content['text']) && is_string($content['text'])) {
                    $text .= $content['text'];
                }
            }
        }
        return $text;
    }

    private function decode_json_text($text) {
        $text = trim((string) $text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        return json_decode(trim($text), true);
    }

    private function allowed_value($value, $allowed, $default) {
        $value = strtolower(sanitize_key((string) $value));
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function fallback_summary($lead_data) {
        $name = $lead_data['name'] ?: 'Visitor';
        $need = $lead_data['message'] ?: $lead_data['intent'] ?: 'business setup help';
        $timeline = $lead_data['timeline'] ?: 'timeline not specified';
        return "{$name} requested {$need}. Timeline: {$timeline}. Follow up to qualify the setup package and next steps.";
    }

    private function lead_body($lead_data, $score) {
        $body = "Lead score: {$score['lead_score']}\n";
        $body .= "Summary: {$score['summary']}\n";
        $body .= "Recommended action: {$score['recommended_action']}\n";
        if (!empty($score['scoring_error'])) {
            $body .= "Scoring status: unscored ({$score['scoring_error']})\n";
        }
        $body .= "\nCollected details\n\n";

        $labels = array(
            'name' => 'Name',
            'phone' => 'Phone',
            'email' => 'Email',
            'message' => 'Business activity / question',
            'preferred_time' => 'Preferred callback time',
            'language' => 'Language',
            'intent' => 'Intent',
            'urgency' => 'Urgency',
            'service_interest' => 'Service interest',
            'setup_location' => 'Setup location',
            'visa_need' => 'Visa need',
            'timeline' => 'Timeline',
            'page' => 'Page',
        );
        foreach ($labels as $key => $label) {
            $value = isset($lead_data[$key]) ? trim((string) $lead_data[$key]) : '';
            if ($value !== '') {
                $body .= "{$label}: {$value}\n";
            }
        }
        return $body;
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
        $profile = $request->get_param('profile');
        $page = esc_url_raw((string) $request->get_param('page'));
        if (!is_array($messages)) {
            $messages = array();
        }
        if (!is_array($lead)) {
            $lead = array();
        }
        if (!is_array($profile)) {
            $profile = array();
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
        foreach (array('language', 'intent', 'urgency', 'service_interest', 'setup_location', 'visa_need', 'timeline') as $key) {
            if (!empty($profile[$key])) {
                $clean_lead[$key] = sanitize_text_field((string) $profile[$key]);
            }
        }
        foreach (array('name', 'phone', 'email', 'message', 'setup_location', 'visa_need', 'timeline', 'preferred_time') as $key) {
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
        $out['scoring_model'] = sanitize_text_field($input['scoring_model'] ?? $defaults['scoring_model']);
        $out['voice'] = sanitize_text_field($input['voice'] ?? $defaults['voice']);
        $out['primary_form_id'] = absint($input['primary_form_id'] ?? $defaults['primary_form_id']);
        $out['lead_email'] = sanitize_email($input['lead_email'] ?? $defaults['lead_email']);
        $out['brand_label'] = sanitize_text_field($input['brand_label'] ?? $defaults['brand_label']);
        $out['greeting'] = sanitize_text_field($input['greeting'] ?? $defaults['greeting']);
        $out['store_sessions'] = empty($input['store_sessions']) ? 0 : 1;
        $out['lead_scoring'] = empty($input['lead_scoring']) ? 0 : 1;
        $out['multilingual'] = empty($input['multilingual']) ? 0 : 1;
        $qualification_depth = sanitize_key($input['qualification_depth'] ?? $defaults['qualification_depth']);
        $out['qualification_depth'] = in_array($qualification_depth, array('short', 'smart', 'deep'), true) ? $qualification_depth : 'smart';
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
                        <th scope="row">Lead scoring model</th>
                        <td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[scoring_model]" value="<?php echo esc_attr($settings['scoring_model']); ?>"></td>
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
                        <th scope="row">Lead scoring</th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[lead_scoring]" value="1" <?php checked(!empty($settings['lead_scoring'])); ?>> Score leads and create a sales-ready summary</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Multilingual mode</th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[multilingual]" value="1" <?php checked(!empty($settings['multilingual'])); ?>> Reply in the visitor language when practical</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Qualification depth</th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPTION); ?>[qualification_depth]">
                                <option value="short" <?php selected($settings['qualification_depth'], 'short'); ?>>Short</option>
                                <option value="smart" <?php selected($settings['qualification_depth'], 'smart'); ?>>Smart 5-step</option>
                                <option value="deep" <?php selected($settings['qualification_depth'], 'deep'); ?>>Deep consultant</option>
                            </select>
                        </td>
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
