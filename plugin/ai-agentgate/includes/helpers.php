<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Extract Bearer token from Authorization header.
 */
function ai_agentgate_get_bearer_token() {
    $h = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if ( ! $h && function_exists('apache_request_headers') ) {
        $headers = apache_request_headers();
        if ( isset($headers['Authorization']) ) {
            $h = $headers['Authorization'];
        } elseif ( isset($headers['authorization']) ) {
            $h = $headers['authorization'];
        }
    }
    if ( stripos($h, 'Bearer ') === 0 ) {
        return substr($h, 7);
    }
    return ''; // treat empty as anonymous bucket
}

/**
 * Build transient key by token (anon if empty).
 */
function ai_agentgate_bucket_key( $token ) {
    $id = $token ? md5( $token ) : 'anon';
    return "ag_rl_" . $id;
}

/**
 * Touch rate bucket ONCE per request and return state.
 * Window: AI_AGENTGATE_RATE_WINDOW ; Limit: AI_AGENTGATE_RATE_LIMIT
 */
function ai_agentgate_rate_touch_and_get( $token ) {
    $now   = time();
    $key   = ai_agentgate_bucket_key( $token );
    $rec   = get_transient( $key );

    if ( ! is_array( $rec ) || empty($rec['reset']) || $rec['reset'] < $now ) {
        $rec = array(
            'limit'     => (int) AI_AGENTGATE_RATE_LIMIT,
            'remaining' => (int) AI_AGENTGATE_RATE_LIMIT,
            'reset'     => $now + (int) AI_AGENTGATE_RATE_WINDOW,
        );
    }

    // Decrement once (not below 0)
    $rec['remaining'] = max( 0, (int)$rec['remaining'] - 1 );

    // Persist for the window
    set_transient( $key, $rec, (int) AI_AGENTGATE_RATE_WINDOW );

    return $rec;
}

/**
 * Append required headers to EVERY REST response and decrement counter exactly once.
 * This runs for both success and WP_Error responses.
 */
function ai_agentgate_append_response_headers( $response, $server, $request ) {
    if ( ! ( $response instanceof WP_REST_Response ) ) {
        // normalize to response to allow headers
        $response = rest_ensure_response( $response );
    }

    $token = ai_agentgate_get_bearer_token();
    $rl    = ai_agentgate_rate_touch_and_get( $token );

    $response->header( 'X-AgentGate-Build',    (string) AI_AGENTGATE_BUILD );
    $response->header( 'X-RateLimit-Limit',    (string) $rl['limit'] );
    $response->header( 'X-RateLimit-Remaining',(string) $rl['remaining'] );
    $response->header( 'X-RateLimit-Reset',    (string) $rl['reset'] );

    return $response;
}
