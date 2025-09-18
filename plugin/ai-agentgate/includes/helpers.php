<?php
/**
 * Helper functions for the AI AgentGate plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! isset( $GLOBALS['ai_agentgate_authenticated_tokens'] ) ) {
    $GLOBALS['ai_agentgate_authenticated_tokens'] = array();
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

    register_rest_route(
        'ai/v1',
        '/content',
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'ai_agentgate_handle_content',
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
            'content' => '/wp-json/ai/v1/content',
        ),
    );

    $payload = apply_filters( 'ai_agentgate_schema_payload', $payload, $request );

    return rest_ensure_response( $payload );
}

/**
 * Handles create and update operations for content.
 *
 * @param WP_REST_Request $request The request instance.
 *
 * @return WP_REST_Response|WP_Error
 */
function ai_agentgate_handle_content( WP_REST_Request $request ) {
    $params = $request->get_json_params();

    if ( ! is_array( $params ) ) {
        $params = $request->get_body_params();
    }

    if ( ! is_array( $params ) ) {
        $params = array();
    }

    $mode = isset( $params['mode'] ) ? sanitize_key( $params['mode'] ) : '';

    if ( '' === $mode ) {
        return new WP_Error(
            'ai_agentgate_missing_mode',
            __( 'The mode parameter is required.', 'ai-agentgate' ),
            array( 'status' => 400 )
        );
    }

    if ( 'create' === $mode ) {
        $post_type = isset( $params['type'] ) ? sanitize_key( $params['type'] ) : 'post';

        if ( ! post_type_exists( $post_type ) ) {
            return new WP_Error(
                'ai_agentgate_invalid_post_type',
                __( 'Invalid post type.', 'ai-agentgate' ),
                array( 'status' => 400 )
            );
        }

        $postarr = array(
            'post_type'   => $post_type,
            'post_status' => 'draft',
        );

        if ( isset( $params['status'] ) && '' !== $params['status'] ) {
            $postarr['post_status'] = sanitize_key( $params['status'] );
        }

        if ( isset( $params['title'] ) ) {
            $postarr['post_title'] = wp_slash( sanitize_text_field( $params['title'] ) );
        }

        if ( isset( $params['content'] ) ) {
            $postarr['post_content'] = wp_slash( wp_kses_post( $params['content'] ) );
        }

        if ( isset( $params['excerpt'] ) ) {
            $postarr['post_excerpt'] = wp_slash( wp_kses_post( $params['excerpt'] ) );
        }

        if ( isset( $params['slug'] ) ) {
            $postarr['post_name'] = sanitize_title( $params['slug'] );
        }

        if ( isset( $params['author'] ) ) {
            $postarr['post_author'] = absint( $params['author'] );
        }

        if ( isset( $params['comment_status'] ) ) {
            $postarr['comment_status'] = sanitize_key( $params['comment_status'] );
        }

        if ( isset( $params['ping_status'] ) ) {
            $postarr['ping_status'] = sanitize_key( $params['ping_status'] );
        }

        if ( isset( $params['password'] ) ) {
            $postarr['post_password'] = sanitize_text_field( $params['password'] );
        }

        if ( isset( $params['date'] ) ) {
            $postarr['post_date'] = sanitize_text_field( $params['date'] );
        }

        if ( isset( $params['date_gmt'] ) ) {
            $postarr['post_date_gmt'] = sanitize_text_field( $params['date_gmt'] );
        }

        $post_id = wp_insert_post( $postarr, true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        if ( isset( $params['featured_image'] ) && '' !== $params['featured_image'] ) {
            $thumbnail = ai_agentgate_assign_featured_image( $post_id, $params['featured_image'] );

            if ( is_wp_error( $thumbnail ) ) {
                return $thumbnail;
            }
        }

        return rest_ensure_response( array( 'id' => $post_id ) );
    }

    if ( 'update' === $mode ) {
        $post_id = isset( $params['id'] ) ? absint( $params['id'] ) : 0;

        if ( ! $post_id ) {
            return new WP_Error(
                'ai_agentgate_missing_post_id',
                __( 'A valid post ID is required for updates.', 'ai-agentgate' ),
                array( 'status' => 400 )
            );
        }

        $post = get_post( $post_id );

        if ( ! $post ) {
            return new WP_Error(
                'ai_agentgate_post_not_found',
                __( 'Post not found.', 'ai-agentgate' ),
                array( 'status' => 404 )
            );
        }

        if ( isset( $params['type'] ) ) {
            $requested_type = sanitize_key( $params['type'] );

            if ( $requested_type !== $post->post_type ) {
                return new WP_Error(
                    'ai_agentgate_post_type_mismatch',
                    __( 'The requested post type does not match the target post.', 'ai-agentgate' ),
                    array( 'status' => 400 )
                );
            }
        }

        $has_featured_image   = array_key_exists( 'featured_image', $params );
        $non_control_keys     = array_diff( array_keys( $params ), array( 'type', 'mode', 'id' ) );
        $only_featured_update = $has_featured_image && empty( array_diff( $non_control_keys, array( 'featured_image' ) ) );

        if ( $only_featured_update ) {
            // AG: featured_image-only early return
            $thumbnail = ai_agentgate_assign_featured_image( $post_id, $params['featured_image'] );

            if ( is_wp_error( $thumbnail ) ) {
                return $thumbnail;
            }

            return rest_ensure_response( array( 'id' => $post_id ) );
        }

        $postarr = array(
            'ID' => $post_id,
        );

        if ( isset( $params['status'] ) && '' !== $params['status'] ) {
            $postarr['post_status'] = sanitize_key( $params['status'] );
        }

        if ( isset( $params['title'] ) ) {
            $postarr['post_title'] = wp_slash( sanitize_text_field( $params['title'] ) );
        }

        if ( isset( $params['content'] ) ) {
            $postarr['post_content'] = wp_slash( wp_kses_post( $params['content'] ) );
        }

        if ( isset( $params['excerpt'] ) ) {
            $postarr['post_excerpt'] = wp_slash( wp_kses_post( $params['excerpt'] ) );
        }

        if ( isset( $params['slug'] ) ) {
            $postarr['post_name'] = sanitize_title( $params['slug'] );
        }

        if ( isset( $params['author'] ) ) {
            $postarr['post_author'] = absint( $params['author'] );
        }

        if ( isset( $params['comment_status'] ) ) {
            $postarr['comment_status'] = sanitize_key( $params['comment_status'] );
        }

        if ( isset( $params['ping_status'] ) ) {
            $postarr['ping_status'] = sanitize_key( $params['ping_status'] );
        }

        if ( isset( $params['password'] ) ) {
            $postarr['post_password'] = sanitize_text_field( $params['password'] );
        }

        if ( isset( $params['date'] ) ) {
            $postarr['post_date'] = sanitize_text_field( $params['date'] );
        }

        if ( isset( $params['date_gmt'] ) ) {
            $postarr['post_date_gmt'] = sanitize_text_field( $params['date_gmt'] );
        }

        $updated = wp_update_post( $postarr, true );

        if ( is_wp_error( $updated ) ) {
            return $updated;
        }

        if ( $has_featured_image ) {
            $thumbnail = ai_agentgate_assign_featured_image( $post_id, $params['featured_image'] );

            if ( is_wp_error( $thumbnail ) ) {
                return $thumbnail;
            }
        }

        return rest_ensure_response( array( 'id' => $post_id ) );
    }

    return new WP_Error(
        'ai_agentgate_invalid_mode',
        __( 'Unsupported mode requested.', 'ai-agentgate' ),
        array( 'status' => 400 )
    );
}

/**
 * Validates and assigns the featured image for a post.
 *
 * @param int   $post_id         The post ID being modified.
 * @param mixed $featured_image  The attachment identifier.
 *
 * @return true|WP_Error
 */
function ai_agentgate_assign_featured_image( $post_id, $featured_image ) {
    $attachment_id = absint( $featured_image );

    if ( $attachment_id <= 0 ) {
        return new WP_Error(
            'ai_agentgate_invalid_featured_image',
            __( 'The featured image must be a valid attachment ID.', 'ai-agentgate' ),
            array( 'status' => 400 )
        );
    }

    $attachment = get_post( $attachment_id );

    if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
        return new WP_Error(
            'ai_agentgate_invalid_featured_image',
            __( 'The featured image must reference an attachment.', 'ai-agentgate' ),
            array( 'status' => 400 )
        );
    }

    if ( ! wp_attachment_is_image( $attachment_id ) ) {
        return new WP_Error(
            'ai_agentgate_invalid_featured_image_type',
            __( 'The featured image must be an image attachment.', 'ai-agentgate' ),
            array( 'status' => 400 )
        );
    }

    if ( ! set_post_thumbnail( $post_id, $attachment_id ) ) {
        return new WP_Error(
            'ai_agentgate_failed_featured_image',
            __( 'Unable to set the featured image.', 'ai-agentgate' ),
            array( 'status' => 500 )
        );
    }

    return true;
}

/**
 * Shared authentication routine for REST API requests.
 *
 * @param WP_REST_Request $request The current request.
 *
 * @return true|WP_Error
 */
function ai_agentgate_authenticate_request( WP_REST_Request $request ) {
    $header = $request->get_header( 'authorization' );

    if ( empty( $header ) ) {
        return new WP_Error(
            'ai_agentgate_missing_token',
            __( 'Authorization header missing.', 'ai-agentgate' ),
            array( 'status' => 401 )
        );
    }

    $token = ai_agentgate_parse_bearer_token( $header );

    if ( empty( $token ) ) {
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

    ai_agentgate_track_authenticated_token( $request, $token );

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
 * Records the authenticated token for the duration of the request.
 *
 * @param WP_REST_Request $request The current request.
 * @param string          $token   The raw token string.
 */
function ai_agentgate_track_authenticated_token( WP_REST_Request $request, $token ) {
    $hash = ai_agentgate_hash_token( $token );
    $key  = ai_agentgate_get_request_storage_key( $request );

    $GLOBALS['ai_agentgate_authenticated_tokens'][ $key ] = array(
        'token' => $token,
        'hash'  => $hash,
    );
}

/**
 * Generates a hash for a token string suitable for storage keys.
 *
 * @param string $token Token value.
 *
 * @return string
 */
function ai_agentgate_hash_token( $token ) {
    return hash( 'sha256', $token );
}

/**
 * Retrieves a storage key for the provided request instance.
 *
 * @param WP_REST_Request $request The request instance.
 *
 * @return string
 */
function ai_agentgate_get_request_storage_key( WP_REST_Request $request ) {
    return spl_object_hash( $request );
}

/**
 * Returns the authenticated token data, if present.
 *
 * @param WP_REST_Request $request The request instance.
 *
 * @return array|null
 */
function ai_agentgate_pop_authenticated_token( WP_REST_Request $request ) {
    $key = ai_agentgate_get_request_storage_key( $request );

    if ( ! isset( $GLOBALS['ai_agentgate_authenticated_tokens'][ $key ] ) ) {
        return null;
    }

    $data = $GLOBALS['ai_agentgate_authenticated_tokens'][ $key ];
    unset( $GLOBALS['ai_agentgate_authenticated_tokens'][ $key ] );

    return $data;
}

/**
 * Updates the rate-limit counters for a token hash and returns the current state.
 *
 * @param string $token_hash The hash representing the token.
 *
 * @return array
 */
function ai_agentgate_touch_rate_limit( $token_hash ) {
    $limit  = (int) apply_filters( 'ai_agentgate_rate_limit', defined( 'AI_AGENTGATE_RATE_LIMIT' ) ? AI_AGENTGATE_RATE_LIMIT : 60 );
    $window = (int) apply_filters( 'ai_agentgate_rate_window', defined( 'AI_AGENTGATE_RATE_WINDOW' ) ? AI_AGENTGATE_RATE_WINDOW : MINUTE_IN_SECONDS );
    $now    = time();

    $key  = ai_agentgate_get_rate_limit_transient_key( $token_hash );
    $data = get_transient( $key );

    if ( ! is_array( $data ) || empty( $data['reset'] ) || $now >= (int) $data['reset'] ) {
        $data = array(
            'limit'     => $limit,
            'remaining' => $limit,
            'reset'     => $now + $window,
        );
    }

    $data['limit'] = $limit;
    $data['remaining'] = min( (int) $data['remaining'], $limit );

    if ( $data['remaining'] > 0 ) {
        $data['remaining'] -= 1;
    } else {
        $data['remaining'] = 0;
    }

    $ttl = max( 1, (int) $data['reset'] - $now );
    set_transient( $key, $data, $ttl );

    return $data;
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
        return $response;
    }

    if ( ! $response instanceof WP_HTTP_Response ) {
        $response = rest_ensure_response( $response );
    }

    $response->header( 'X-AgentGate-Build', AI_AGENTGATE_BUILD );

    $token_data = ai_agentgate_pop_authenticated_token( $request );

    if ( $token_data && ! empty( $token_data['hash'] ) ) {
        $rate = ai_agentgate_touch_rate_limit( $token_data['hash'] );

        if ( is_array( $rate ) ) {
            $response->header( 'X-RateLimit-Limit', (int) $rate['limit'] );
            $response->header( 'X-RateLimit-Remaining', max( 0, (int) $rate['remaining'] ) );
            $response->header( 'X-RateLimit-Reset', (int) $rate['reset'] );
        }
    }

    return $response;
}
