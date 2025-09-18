<?php
/**
 * Plugin Name: AI AgentGate
 * Description: Minimal REST scaffold for CI tests (schema + global headers + rate limiting).
 * Version: 5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

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
    define( 'AI_AGENTGATE_RATE_LIMIT', 120 ); // requests per window
}
if ( ! defined( 'AI_AGENTGATE_RATE_WINDOW' ) ) {
    define( 'AI_AGENTGATE_RATE_WINDOW', defined('MINUTE_IN_SECONDS') ? 10 * MINUTE_IN_SECONDS : 600 );
}

require_once AI_AGENTGATE_PLUGIN_DIR . 'includes/helpers.php';

/**
 * Bootstrap: register routes & attach headers.
 */
function ai_agentgate_bootstrap() {
    add_action( 'rest_api_init', 'ai_agentgate_register_routes' );
    // IMPORTANT: single mutation point for rate counter + all headers
    add_filter( 'rest_post_dispatch', 'ai_agentgate_append_response_headers', 10, 3 );
}
add_action( 'plugins_loaded', 'ai_agentgate_bootstrap' );

/**
 * Register /ai/v1/schema
 */
function ai_agentgate_register_routes() {
    register_rest_route( 'ai/v1', '/schema', array(
        'methods'  => 'GET',
        'permission_callback' => '__return_true', // auth to be added later
        'callback' => function( WP_REST_Request $req ) {
            $data = array(
                'version' => AI_AGENTGATE_BUILD,
                'status'  => 'ok',
            );
            return new WP_REST_Response( $data, 200 );
        },
    ) );
}
