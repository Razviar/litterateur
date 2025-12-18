<?php
/**
 * Response helper class for Texter API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texter_API_Response {
    
    /**
     * Send a success response
     *
     * @param mixed $data Response data
     * @param int $status HTTP status code
     * @return WP_REST_Response
     */
    public static function success($data = null, $status = 200) {
        $response = array(
            'success' => true,
        );
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        return new WP_REST_Response($response, $status);
    }
    
    /**
     * Send an error response
     *
     * @param string $message Error message
     * @param string $code Error code
     * @param int $status HTTP status code
     * @return WP_REST_Response
     */
    public static function error($message, $code = 'error', $status = 400) {
        return new WP_REST_Response(array(
            'success' => false,
            'error' => array(
                'code' => $code,
                'message' => $message,
            ),
        ), $status);
    }
    
    /**
     * Send an unauthorized response
     *
     * @param string $message Error message
     * @return WP_REST_Response
     */
    public static function unauthorized($message = 'Invalid or missing API key') {
        return self::error($message, 'unauthorized', 401);
    }
    
    /**
     * Send a not found response
     *
     * @param string $message Error message
     * @return WP_REST_Response
     */
    public static function not_found($message = 'Resource not found') {
        return self::error($message, 'not_found', 404);
    }
    
    /**
     * Send a validation error response
     *
     * @param string $message Error message
     * @param array $errors Validation errors
     * @return WP_REST_Response
     */
    public static function validation_error($message, $errors = array()) {
        $response = array(
            'success' => false,
            'error' => array(
                'code' => 'validation_error',
                'message' => $message,
            ),
        );
        
        if (!empty($errors)) {
            $response['error']['details'] = $errors;
        }
        
        return new WP_REST_Response($response, 422);
    }
}
