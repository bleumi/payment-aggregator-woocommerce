<?php
if (!defined('ABSPATH'))
{
    exit;
}

class Bleumi_PA_APIHandler
{
    public static $curlopt_ssl_verifypeer = false;

    /** @var string/array Log variable function. */
    public static $log;

    /**
     * Call the $log variable function.
     *
     * @param string $message Log message.
     * @param string $level   Optional. Default 'info'.
     *     emergency|alert|critical|error|warning|notice|info|debug
     */
    public static function log($message, $level = 'info')
    {
        return call_user_func(self::$log, $message, $level);
    }

    /** @var string Bleumi API url. */
    public static $endpoint_url = 'https://api.bleumi.io/v1/payment/';

    /** @var string Bleumi API key. */
    public static $api_key;

    public static function sendRequest($requestParams, $method = 'GET')
    {
        $headers = array(
            'X-Api-Key' => self::$api_key,
            'Content-Type' => 'application/json'
        );

        if (in_array($method, array(
            'POST',
            'PUT'
        )))
        {
            self::log("[INFO] creating a payment request");

            $data_to_post = json_encode($requestParams);
            self::log("[INFO] " . $data_to_post);

            $args = array(
                'body' => $data_to_post,
                'headers' => $headers,
                'timeout' => 120
            );

            $response = wp_remote_post(self::$endpoint_url, $args);
        }
        else
        {
            self::log("[INFO] fetching payment data");
            $args = array(
                'headers' => $headers,
                'timeout' => 120
            );
            $response = wp_remote_get(self::$endpoint_url . $requestParams, $args);
        }

        if (is_wp_error($response))
        {
            self::log("[INFO] HTTP Failed: " . $response->get_error_message());
            return;
        }

        $body = wp_remote_retrieve_body($response);

        $http_code = wp_remote_retrieve_response_code($response);

        self::log('[INFO] HTTP status: ' . $http_code);
        self::log('[INFO] HTTP body: ' . $body);

        if ($http_code === 200)
        {
            $response = json_decode($body, true);
            return $response;
        }
    }
}