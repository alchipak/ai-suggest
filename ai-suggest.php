<?php
/**
 * Plugin Name: AI Suggest
 * Plugin URI: http://seudominio.com
 * Description: Um plugin que sugere novos títulos e descrições de conteúdo baseados em GPT ao criar um artigo no WordPress. Permite ao usuário marcar sugestões que serão salvas como rascunhos.
 * Version: 1.0.0
 * Author: A L Chipak
 * Author URI: http://chipak.com.br
 * License: GPL2
 */

 // Register a meta box for displaying GPT-generated article suggestions in the post editor
function ai_suggest_add_meta_box() {
    add_meta_box('ai-suggest-meta-box', 'AI Suggest', 'ai_suggest_add_meta_box_callback', 'post');
}

function ai_suggest_add_meta_box_callback($post) {
    echo '
    <style>
    .ai-suggest-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 10px;
    }
    .ai-suggest-title {
        /* Estilize conforme necessário */
    }
    .ai-suggest-description {
        /* Estilize conforme necessário */
    }
    </style>
    <div id="ai-suggest-suggestions"></div>
    ';
}

// Enqueue necessary scripts for the plugin's AJAX functionality
function ai_suggest_enqueue_scripts($hook) {
    // Load JavaScript files and pass PHP data to them
    if ('post.php' !== $hook && 'post-new.php' !== $hook) {
        return;
    }

    wp_enqueue_script('ai-suggest-ajax', plugin_dir_url(__FILE__) . 'ajax.js', array('jquery'), null, true);
    wp_localize_script('ai-suggest-ajax', 'aiSuggestAjax', array('ajax_url' => admin_url('admin-ajax.php')));

    $api_key = get_option('ai_suggest_api_key');
    wp_localize_script('ai-suggest-ajax', 'aiSuggestAjax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'api_key' => $api_key
    ));

    $nonce = wp_create_nonce('ai_suggest_nonce');
    wp_localize_script('ai-suggest-ajax', 'aiSuggestAjax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'api_key' => $api_key,
        'nonce' => $nonce
    ));
}

// AJAX action handlers to save and undo drafts based on GPT suggestions
function ai_suggest_save_draft() {
    // Check security nonce and create a draft post with the given title and content
    check_ajax_referer('ai_suggest_nonce', 'nonce');

    $title = sanitize_text_field($_POST['title']);
    $content = sanitize_text_field($_POST['content']);

    $post_id = wp_insert_post(array(
        'post_title' => $title,
        'post_content' => $content,
        'post_status' => 'draft',
        'post_author' => get_current_user_id()
    ));

    if ($post_id) {
        wp_send_json_success(array('draftId' => $post_id));
    } else {
        wp_send_json_error('Não foi possível criar o rascunho.');
    }
}

function ai_suggest_undo_draft() {
    // Check security nonce and delete the specified draft if permissions allow
    check_ajax_referer('ai_suggest_nonce', 'nonce');

    $draft_id = intval($_POST['draftId']);

    if (get_current_user_id() == get_post_field('post_author', $draft_id)) {
        wp_delete_post($draft_id, true);
        wp_send_json_success();
    } else {
        wp_send_json_error('Você não tem permissão para excluir este rascunho.');
    }
}

// Admin menu functions to add a settings page for the plugin
function ai_suggest_add_admin_menu() {
    // Add menu and submenu pages for plugin settings
    add_menu_page('AI Suggest', 'AI Suggest', 'manage_options', 'ai_suggest', 'ai_suggest_settings_page', 'dashicons-admin-generic');
    add_submenu_page('ai_suggest', 'Settings', 'Settings', 'manage_options', 'ai_suggest_settings', 'ai_suggest_settings_page');
}

function ai_suggest_settings_page() {
    // Display the settings form
    ?>
    <div class="wrap">
    <h2>AI Suggest Settings</h2>
    <form action="options.php" method="POST">
        <?php
        settings_fields('ai_suggest_options');
        do_settings_sections('ai_suggest');
        submit_button();
        ?>
    </form>
    </div>
    <?php
}

// Register plugin settings and define fields for API key configuration
function ai_suggest_register_settings() {
    // Register settings, sections, and fields
    register_setting('ai_suggest_options', 'ai_suggest_api_key');
    add_settings_section('ai_suggest_main', 'Main Settings', 'ai_suggest_main_callback', 'ai_suggest');
    add_settings_field('ai_suggest_api_key_field', 'ChatGPT API Key', 'ai_suggest_api_key_field_callback', 'ai_suggest', 'ai_suggest_main');
}

function ai_suggest_main_callback() {
    echo '<p>Enter your settings below:</p>';
}

function ai_suggest_api_key_field_callback() {
    $apiKey = get_option('ai_suggest_api_key');
    echo '<input type="text" id="ai_suggest_api_key" name="ai_suggest_api_key" value="' . esc_attr($apiKey) . '"/>';
}

// Function to generate GPT suggestions based on post title and content
function ai_suggest_get_gpt_suggestions() {
    // Fetches suggestions using the OpenAI API based on the context provided
    $api_key = get_option('ai_suggest_api_key');
    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
    $content = isset($_POST['content']) ? sanitize_text_field($_POST['content']) : '';

    $clean_content = wp_strip_all_tags($content);

    $limited_content = substr($clean_content, -500);
    $prompt = "(Quero que você no idioma que está sendo falado aqui {$title}) \n
               Liste 1 ideia de artigo baseado no CONTEXTO abaixo. Essas ideias deverão conter um título e uma descrição, cada uma, do que poderia ser abordado. \n
               O título deve ter menos de 65 caracteres e serem otimizados para SEO. A descrição deve ter 500 caracteres e ser bem completa e detalhada.\n
               Por exemplo: \n
               Como Transformar $1 em 1 milhão em 60 dias: Nesse artigo vamos abordar estratégias eficientes para investir apenas $1 e transformá-lo em 1 milhão através de tarefas simples e que podemos fazer no cotidiano. Esse artigo abordará temas como o inicio do trabalho, técnicas que podem ser aplicadas entre outras.\n
               Por favor, NÃO coloque nada entre aspas e NÃO numere o título nem a descrição. Quero que você seja criativo.\n
               CONTEXTO: $limited_content...";

    $messages = [
        [
            "role" => "user",
            "content" => $prompt
        ]
    ];

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key
        ),
        'body' => json_encode(array(
            'model' => 'gpt-3.5-turbo',
            'messages' => $messages
        )),
        'timeout' => 90
    ));

    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    }

    $body = wp_remote_retrieve_body($response);
    $decoded_body = json_decode($body, true);

    wp_send_json_success($decoded_body);

    // Register the functions with WordPress hooks
}


function ai_suggest_load_textdomain() {
    load_plugin_textdomain('ai-suggest-domain', false, basename(dirname(__FILE__)) . '/languages/');
}

add_action('plugins_loaded', 'ai_suggest_load_textdomain');


// In your PHP file where you enqueue your script
wp_enqueue_script('ai-suggest-ajax', plugin_dir_url(__FILE__) . 'ajax.js', array('jquery'), null, true);

// Localize the script with new data
$translation_array = array(
    'notEnoughContent' => __('Not enough content to generate suggestions.', 'ai-suggest-domain'),
    'requestInProgress' => __('A request is already in progress. Please wait.', 'ai-suggest-domain'),
    'generatingSuggestions' => __('Generating suggestions...', 'ai-suggest-domain'),
    'clickToGenerate' => __('Click to generate suggestions', 'ai-suggest-domain'),
    'unknownError' => __('An unknown error occurred:', 'ai-suggest-domain'),
    'draftSaved' => __('Draft saved successfully.', 'ai-suggest-domain'),
    'draftDeleted' => __('Draft deleted successfully.', 'ai-suggest-domain'),
    'errorSavingDraft' => __('Error saving draft:', 'ai-suggest-domain'),
    'errorDeletingDraft' => __('Error deleting draft:', 'ai-suggest-domain'),
    'save' => __('Save', 'ai-suggest-domain'),
    'generate' => __('Generate', 'ai-suggest-domain'),
    'undo' => __('Undo', 'ai-suggest-domain')
);
wp_localize_script('ai-suggest-ajax', 'aiSuggestLocalize', $translation_array);


// Register the functions with WordPress hooks
add_action('add_meta_boxes', 'ai_suggest_add_meta_box');
add_action('admin_enqueue_scripts', 'ai_suggest_enqueue_scripts');
add_action('wp_ajax_ai_suggest_save_draft', 'ai_suggest_save_draft');
add_action('wp_ajax_ai_suggest_undo_draft', 'ai_suggest_undo_draft');
add_action('admin_menu', 'ai_suggest_add_admin_menu');
add_action('admin_init', 'ai_suggest_register_settings');
add_action('wp_ajax_ai_suggest_get_gpt_suggestions', 'ai_suggest_get_gpt_suggestions');




