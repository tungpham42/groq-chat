<?php
/**
 * Plugin Name: Groq Chat
 * Plugin URI:  https://soft.io.vn/groq-chat
 * Description: An AI Chat Widget powered by Groq that answers questions based on your website's content.
 * Version:     1.0.0
 * Author:      Tung Pham
 * License:     GPL-2.0+
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// 1. REGISTER SETTINGS & ADMIN MENU
add_action('admin_menu', 'groq_chat_add_admin_menu');
add_action('admin_init', 'groq_chat_settings_init');

function groq_chat_add_admin_menu() {
    add_options_page(
        'Groq Chat Settings',
        'Groq Chat',
        'manage_options',
        'groq-chat',
        'groq_chat_options_page'
    );
}

function groq_chat_settings_init() {
    register_setting('groqChat', 'groq_chat_settings');

    add_settings_section(
        'groq_chat_section',
        __('API Configuration', 'groq-chat'),
        'groq_chat_section_callback',
        'groqChat'
    );

    add_settings_field(
        'api_key',
        __('Groq API Key', 'groq-chat'),
        'groq_chat_apikey_render',
        'groqChat',
        'groq_chat_section'
    );

    add_settings_field(
        'model',
        __('AI Model', 'groq-chat'),
        'groq_chat_model_render',
        'groqChat',
        'groq_chat_section'
    );
}

function groq_chat_apikey_render() {
    $options = get_option('groq_chat_settings');
    ?>
    <input type='password' name='groq_chat_settings[api_key]' value='<?php echo isset($options['api_key']) ? esc_attr($options['api_key']) : ''; ?>' style="width: 400px;">
    <p class="description">Get your key at <a href="https://console.groq.com/keys" target="_blank">console.groq.com</a></p>
    <?php
}

function groq_chat_model_render() {
    $options = get_option('groq_chat_settings');
    $value = isset($options['model']) ? $options['model'] : 'openai/gpt-oss-120b';
    ?>
    <input type='text' name='groq_chat_settings[model]' value='<?php echo esc_attr($value); ?>' style="width: 400px;">
    <p class="description">Recommended: <code>openai/gpt-oss-120b</code>, <code>openai/gpt-oss-20b</code>, or <code>llama-3.3-70b-versatile</code>.</p>
    <?php
}

function groq_chat_section_callback() {
    echo __('Configure your Groq API connection details below.', 'groq-chat');
}

function groq_chat_options_page() {
    ?>
    <div class="wrap">
        <h1>Groq Chat Settings</h1>
        <form action='options.php' method='post'>
            <?php
            settings_fields('groqChat');
            do_settings_sections('groqChat');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// 2. REST API ENDPOINT
add_action('rest_api_init', function () {
    register_rest_route('groq-widget/v1', '/ask', [
        'methods' => 'POST',
        'callback' => 'groq_chat_handle_request',
        'permission_callback' => '__return_true',
    ]);
});

function groq_chat_handle_request($request) {
    $options = get_option('groq_chat_settings');
    $api_key = isset($options['api_key']) ? $options['api_key'] : '';
    $model   = isset($options['model']) && !empty($options['model']) ? $options['model'] : 'openai/gpt-oss-120b';

    if (empty($api_key)) {
        return new WP_Error('missing_config', 'API Key chưa được cấu hình trong admin.', ['status' => 500]);
    }

    $params = $request->get_json_params();
    $question = sanitize_text_field($params['question'] ?? '');

    if (empty($question)) {
        return new WP_Error('missing_params', 'Vui lòng nhập câu hỏi', ['status' => 400]);
    }

    // --- CONTEXT BUILDING START ---
    // Fetch top 120 most recent posts/pages
    $args = [
        'post_type'      => ['post', 'page'],
        'post_status'    => 'publish',
        'posts_per_page' => 120, 
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];

    $all_content = get_posts($args);
    $context = "";
    $max_context_length = 25000; // Characters limit

    foreach ($all_content as $post) {
        $title = $post->post_title;
        $link = get_permalink($post->ID);
        $raw_content = wp_strip_all_tags($post->post_content);
        $clean_content = preg_replace('/\s+/', ' ', $raw_content);
        
        $entry = "Tiêu đề: $title\nLink: $link\nNội dung: $clean_content\n---\n";

        if (strlen($context) + strlen($entry) > $max_context_length) {
            break; 
        }
        $context .= $entry;
    }
    // --- CONTEXT BUILDING END ---

    $payload = [
        'model' => $model,
        'messages' => [
            [
                'role' => 'system',
                'content' => "Bạn là trợ lý AI của website này. " .
                             "Dưới đây là nội dung các bài viết trên website.\n" .
                             "Trả lời câu hỏi dựa trên thông tin này.\n" .
                             "Trả lời bằng Tiếng Việt.\n" .
                             "Nếu tìm thấy thông tin, hãy cung cấp đường Link bài viết.\n" .
                             "Dùng Markdown để định dạng.\n\n" .
                             "Context Data:\n" . $context
            ],
            [
                'role' => 'user',
                'content' => $question
            ]
        ],
        'temperature' => 0.8,
        'max_tokens' => 4096,
    ];

    $response = wp_remote_post('https://api.groq.com/openai/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode($payload),
        'timeout' => 30
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('api_error', $response->get_error_message(), ['status' => 500]);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $answer = $body['choices'][0]['message']['content'] ?? 'Xin lỗi, không thể kết nối tới AI lúc này.';

    return rest_ensure_response(['answer' => $answer]);
}

// 3. FRONTEND WIDGET
add_action('wp_footer', 'groq_chat_inject_widget');

function groq_chat_inject_widget() {
    // Only show if API Key is configured and not in Admin dashboard
    $options = get_option('groq_chat_settings');
    if (is_admin() || empty($options['api_key'])) return; 
    ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/marked/16.3.0/lib/marked.umd.min.js" integrity="sha512-V6rGY7jjOEUc7q5Ews8mMlretz1Vn2wLdMW/qgABLWunzsLfluM0FwHuGjGQ1lc8jO5vGpGIGFE+rTzB+63HdA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

    <style>
        /* --- Widget CSS --- */
        #groq-widget-trigger {
            position: fixed; bottom: 20px; right: 20px; width: 60px; height: 60px;
            background-color: #027DDD; color: white; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 999999; transition: transform 0.2s; font-size: 24px;
        }
        #groq-widget-trigger:hover { transform: scale(1.05); }

        #groq-widget-window {
            position: fixed; bottom: 90px; right: 20px; width: 350px; height: 400px;
            background: white; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.2);
            display: none; flex-direction: column; z-index: 9999; overflow: hidden;
            border: 1px solid #e0e0e0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        
        .gw-header { background: #027DDD; color: white; padding: 15px; font-weight: bold; display: flex; justify-content: space-between; align-items: center; }
        .gw-close { cursor: pointer; font-size: 18px; }

        #gw-messages { flex: 1; padding: 15px; overflow-y: auto; background: #f9f9f9; display: flex; flex-direction: column; gap: 10px; }
        
        .gw-msg { max-width: 85%; padding: 10px 14px; border-radius: 10px; font-size: 14px; line-height: 1.5; word-wrap: break-word; }
        .gw-msg.user { align-self: flex-end; background: #333; color: white; border-bottom-right-radius: 2px; }
        .gw-msg.bot { align-self: flex-start; background: #fff; border: 1px solid #ddd; border-bottom-left-radius: 2px; color: #333; }
        
        /* Markdown Styles */
        .gw-msg.bot p { margin: 0 0 10px 0; }
        .gw-msg.bot p:last-child { margin-bottom: 0; }
        .gw-msg.bot ul, .gw-msg.bot ol { margin: 0 0 10px 20px; padding: 0; }
        .gw-msg.bot strong { font-weight: 700; color: #000; }
        .gw-msg.bot pre { background: #2d2d2d; color: #f8f8f2; padding: 10px; border-radius: 6px; overflow-x: auto; margin: 10px 0; font-size: 12px; }
        .gw-msg.bot code { font-family: monospace; background: #eee; padding: 2px 4px; border-radius: 3px; color: #d63384; }
        .gw-msg.bot pre code { background: transparent; color: inherit; }
        .gw-msg.bot a { color: #027DDD; text-decoration: underline; }

        .gw-input-area { padding: 10px; border-top: 1px solid #eee; background: white; display: flex; gap: 10px; }
        #gw-input { flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 6px; outline: none; font-size: 14px; }
        #gw-send { padding: 0 15px; background: #027DDD; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
        #gw-send:disabled { background: #ccc; cursor: not-allowed; }

        @media (max-width: 768px) {
            #groq-widget-trigger { bottom: 80px; }
            #groq-widget-window { bottom: 150px; right: 10px; left: 10px; width: auto; }
        }
    </style>

    <div id="groq-widget-trigger" onclick="toggleGroqWidget()">💬</div>
    <div id="groq-widget-window">
        <div class="gw-header">
            <span>Hỏi AI về Website</span>
            <span class="gw-close" onclick="toggleGroqWidget()">✕</span>
        </div>
        <div id="gw-messages">
            <div class="gw-msg bot">Xin chào! Tôi có thể giúp gì cho bạn?</div>
        </div>
        <div class="gw-input-area">
            <input type="text" id="gw-input" placeholder="Nhập câu hỏi..." onkeypress="handleEnter(event)">
            <button id="gw-send" onclick="askGroq()">Gửi</button>
        </div>
    </div>

    <script>
        const apiUrl = '<?php echo esc_url(rest_url('groq-widget/v1/ask')); ?>';

        function toggleGroqWidget() {
            const win = document.getElementById('groq-widget-window');
            win.style.display = win.style.display === 'flex' ? 'none' : 'flex';
            if (win.style.display === 'flex') document.getElementById('gw-input').focus();
        }

        function handleEnter(e) { if (e.key === 'Enter') askGroq(); }

        async function askGroq() {
            const input = document.getElementById('gw-input');
            const messages = document.getElementById('gw-messages');
            const btn = document.getElementById('gw-send');
            const question = input.value.trim();

            if (!question) return;

            messages.innerHTML += `<div class="gw-msg user">${question.replace(/</g, "&lt;")}</div>`;
            messages.innerHTML += `<div class="gw-msg loading" id="gw-loading" style="background:none; color:#888; font-style:italic;">Đang suy nghĩ...</div>`;
            messages.scrollTop = messages.scrollHeight;
            
            input.value = ''; input.disabled = true; btn.disabled = true;

            try {
                const res = await fetch(apiUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ question: question })
                });

                const data = await res.json();
                const loader = document.getElementById('gw-loading');
                if(loader) loader.remove();

                if (data.answer) {
                    const htmlAnswer = marked.parse(data.answer);
                    messages.innerHTML += `<div class="gw-msg bot">${htmlAnswer}</div>`;
                } else {
                    const errorMsg = data.message || 'Lỗi không xác định';
                    messages.innerHTML += `<div class="gw-msg bot" style="color:red">Lỗi: ${errorMsg}</div>`;
                }

            } catch (err) {
                const loader = document.getElementById('gw-loading');
                if(loader) loader.remove();
                messages.innerHTML += `<div class="gw-msg bot" style="color:red">Lỗi kết nối mạng.</div>`;
                console.error(err);
            }

            input.disabled = false; btn.disabled = false;
            input.focus(); messages.scrollTop = messages.scrollHeight;
        }
    </script>
    <?php
}