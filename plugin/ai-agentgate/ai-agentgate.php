<?php
/**
 * Plugin Name: AI AgentGate
 * Description: REST endpoints and helpers for the AI AgentGate integration.
 * Version: 5.0.0
 * Author: AI AgentGate Team
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'AI_AGENTGATE_VERSION' ) ) {
    define( 'AI_AGENTGATE_VERSION', '5.0.0' );
}

if ( ! defined( 'AI_AGENTGATE_BUILD' ) ) {
    define( 'AI_AGENTGATE_BUILD', AI_AGENTGATE_VERSION );
}

if ( ! defined( 'AI_AGENTGATE_PLUGIN_DIR' ) ) {
    define( 'AI_AGENTGATE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'AI_AGENTGATE_RATE_LIMIT' ) ) {
    define( 'AI_AGENTGATE_RATE_LIMIT', 120 );
}

if ( ! defined( 'AI_AGENTGATE_RATE_WINDOW' ) ) {
    define( 'AI_AGENTGATE_RATE_WINDOW', 600 );
}

require_once AI_AGENTGATE_PLUGIN_DIR . 'includes/helpers.php';

/**
 * Registers the WordPress hooks used by the plugin.
 */
function ai_agentgate_bootstrap() {
    add_action( 'rest_api_init', 'ai_agentgate_register_routes' );
    add_filter( 'rest_post_dispatch', 'ai_agentgate_append_response_headers', 10, 3 );
}

add_action( 'plugins_loaded', 'ai_agentgate_bootstrap' );
