<?php
/**
 * Helper functions for the AI AgentGate plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the REST API routes for the plugin.
 */
function ai_agentgate_register_routes() {
    register_rest_route(
        'ai/v1',
        '/schema',
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'ai_agentgate_rest_schema',
            'permission_callback' => 'ai_agentgate_authenticate_request',
        )
    );
}

/**
 * REST callback that returns the schema payload.
 *
 * @param WP_REST_Request $request The request instance.
 *
 * @return WP_REST_Response
 */
function ai_agentgate_rest_schema( WP_REST_Request $request ) {
    $payload = array(
        'name'    => 'ai-agentgate',
        'version' => AI_AGENTGATE_VERSION,
        'build'   => AI_AGENTGATE_BUILD,
        'routes'  => array(
            'schema' => '/wp-json/ai/v1/schema',
        ),
    );

    $payload = apply_filters( 'ai_agentgate_schema_payload', $payload, $request );

    return rest_ensure_response( $payload );
}

/**
 * Shared authentication routine for REST API requests.
 *
 * @param WP_REST_Request $request The current request.
 *
 * @return true|WP_Error
 */
function ai_agentgate_authenticate_request( WP_REST_Request $request ) {
    $header         = $request->get_header( 'authorization' );
    $token          = ai_agentgate_parse_bearer_token( $header );
    $header_missing = empty( $header );

    if ( null === $token ) {
        $fallback_token = ai_agentgate_get_bearer_token();

        if ( null !== $fallback_token ) {
            $token          = $fallback_token;
            $header_missing = false;
        }
    }

    if ( null === $token ) {
        if ( $header_missing ) {
            return new WP_Error(
                'ai_agentgate_missing_token',
                __( 'Authorization header missing.', 'ai-agentgate' ),
                array( 'status' => 401 )
            );
        }

        return new WP_Error(
            'ai_agentgate_invalid_token',
            __( 'Invalid Authorization header.', 'ai-agentgate' ),
            array( 'status' => 401 )
        );
    }

    $active_tokens   = ai_agentgate_get_active_tokens();
    $disabled_tokens = ai_agentgate_get_disabled_tokens();

    if ( in_array( $token, $disabled_tokens, true ) ) {
        return new WP_Error(
            'ai_agentgate_token_disabled',
            __( 'The supplied token is disabled.', 'ai-agentgate' ),
            array( 'status' => 403 )
        );
    }

    if ( empty( $active_tokens ) || ! in_array( $token, $active_tokens, true ) ) {
        return new WP_Error(
            'ai_agentgate_token_forbidden',
            __( 'The supplied token is not authorized.', 'ai-agentgate' ),
            array( 'status' => 403 )
        );
    }

    return true;
}

/**
 * Attempts to parse a bearer token from the Authorization header.
 *
 * @param string $header The Authorization header value.
 *
 * @return string|null The token string when present, otherwise null.
 */
function ai_agentgate_parse_bearer_token( $header ) {
    if ( ! is_string( $header ) ) {
        return null;
    }

    if ( preg_match( '/Bearer\s+(.*)$/i', $header, $matches ) ) {
        $token = trim( $matches[1] );
        if ( '' !== $token ) {
            return $token;
        }
    }

    return null;
}

/**
 * Attempts to retrieve the bearer token from the current request.
 *
 * @return string|null The token string when present, otherwise null.
 */
function ai_agentgate_get_bearer_token() {
    $header = null;

    if ( function_exists( 'apache_request_headers' ) ) {
        $headers = apache_request_headers();

        if ( is_array( $headers ) ) {
            if ( isset( $headers['Authorization'] ) ) {
                $header = $headers['Authorization'];
            } elseif ( isset( $headers['authorization'] ) ) {
                $header = $headers['authorization'];
            }
        }
    }

    if ( null === $header ) {
        if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
            $header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
    }

    if ( null === $header ) {
        return null;
    }

    return ai_agentgate_parse_bearer_token( $header );
}

/**
 * Retrieves the list of active tokens from environment variables and options.
 *
 * @return array
 */
function ai_agentgate_get_active_tokens() {
    $tokens = ai_agentgate_collect_tokens_from_sources(
        getenv( 'AI_AGENTGATE_ACTIVE_TOKENS' ),
        function_exists( 'get_option' ) ? get_option( 'ai_agentgate_active_tokens' ) : array()
    );

    return apply_filters( 'ai_agentgate_active_tokens', $tokens );
}

/**
 * Retrieves the list of disabled tokens from environment variables and options.
 *
 * @return array
 */
function ai_agentgate_get_disabled_tokens() {
    $tokens = ai_agentgate_collect_tokens_from_sources(
        getenv( 'AI_AGENTGATE_DISABLED_TOKENS' ),
        function_exists( 'get_option' ) ? get_option( 'ai_agentgate_disabled_tokens' ) : array()
    );

    return apply_filters( 'ai_agentgate_disabled_tokens', $tokens );
}

/**
 * Normalizes one or more token sources into an array.
 *
 * @param mixed ...$sources String or array sources that may contain tokens.
 *
 * @return array
 */
function ai_agentgate_collect_tokens_from_sources( ...$sources ) {
    $tokens = array();

    foreach ( $sources as $source ) {
        if ( empty( $source ) ) {
            continue;
        }

        if ( is_string( $source ) ) {
            $parts = preg_split( '/[\s,]+/', $source );
        } elseif ( is_array( $source ) ) {
            $parts = $source;
        } else {
            continue;
        }

        foreach ( $parts as $part ) {
            $part = trim( (string) $part );
            if ( '' !== $part ) {
                $tokens[] = $part;
            }
        }
    }

    if ( empty( $tokens ) ) {
        return array();
    }

    $tokens = array_values( array_unique( $tokens ) );

    return $tokens;
}

/**
 * Updates and returns the current rate limit counters for the provided token.
 *
 * @param string|null $token The bearer token string.
 *
 * @return array{
 *     limit:int,
 *     remaining:int,
 *     reset:int,
 * }
 */
function ai_agentgate_rate_touch_and_get( $token ) {
    $window = 600;
    $limit  = 120;
    $hash   = $token ? md5( (string) $token ) : 'anon';
    $key    = ai_agentgate_get_rate_limit_transient_key( $hash );
    $now    = time();

    $data = get_transient( $key );

    if ( ! is_array( $data ) ) {
        $data = array();
    }

    if ( empty( $data['reset'] ) || $now >= (int) $data['reset'] ) {
        $data['remaining'] = $limit;
        $data['reset']     = $now + $window;
    }

    $data['limit'] = $limit;

    $remaining = isset( $data['remaining'] ) ? (int) $data['remaining'] : $limit;
    if ( $remaining > $limit ) {
        $remaining = $limit;
    }

    if ( $remaining < 0 ) {
        $remaining = 0;
    }

    if ( $remaining > 0 ) {
        $remaining -= 1;
    }

    $data['remaining'] = $remaining;

    set_transient( $key, $data, $window );

    return array(
        'limit'     => (int) $data['limit'],
        'remaining' => (int) $data['remaining'],
        'reset'     => (int) $data['reset'],
    );
}

/**
 * Builds the transient key used for rate limiting storage.
 *
 * @param string $token_hash Token hash.
 *
 * @return string
 */
function ai_agentgate_get_rate_limit_transient_key( $token_hash ) {
    return 'ai_agentgate_rl_' . $token_hash;
}

/**
 * Appends common headers to REST API responses.
 *
 * @param WP_REST_Response|WP_HTTP_Response $response The response instance.
 * @param WP_REST_Server                    $server   The server instance.
 * @param WP_REST_Request                   $request  The original request.
 *
 * @return WP_REST_Response|WP_HTTP_Response
 */
function ai_agentgate_append_response_headers( $response, $server, $request ) {
    if ( is_wp_error( $response ) ) {
        $response = rest_convert_error_to_response( $response );
    } else {
        $response = rest_ensure_response( $response );
    }

    if ( ! $response instanceof WP_HTTP_Response ) {
        return $response;
    }

    $token = ai_agentgate_get_bearer_token();
    $rate  = ai_agentgate_rate_touch_and_get( $token );

    $response->header( 'X-AgentGate-Build', AI_AGENTGATE_BUILD );
    $response->header( 'X-RateLimit-Limit', (string) $rate['limit'] );
    $response->header( 'X-RateLimit-Remaining', (string) $rate['remaining'] );
    $response->header( 'X-RateLimit-Reset', (string) $rate['reset'] );

    return $response;
}
