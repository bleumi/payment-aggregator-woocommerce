<?php

if (!defined('ABSPATH'))
{
    exit;
}

class Bleumi_APIHandler
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
        $request_headers = array();
        $request_headers[] = 'X-Api-Key: ' . self::$api_key;
        $request_headers[] = 'Content-Type: application/json';

        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_HTTP200ALIASES, array(
            400
        ));

        $data_to_post = json_encode($requestParams);

        if (in_array($method, array(
            'POST',
            'PUT'
        )))
        {
            self::log("[INFO] creating a payment request...");

            curl_setopt($ch, CURLOPT_URL, self::$endpoint_url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_to_post);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        }
        else
        {
            self::log("[INFO] fetching payment status...");

            curl_setopt($ch, CURLOPT_URL, self::$endpoint_url . $data_to_post);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }

        $response_body = curl_exec($ch);
        self::log('[INFO] curl response: ' . $response_body);

        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        self::log('[INFO] curl http status: ' . $http_status);

        $response = json_decode($response_body, true);

        if ($http_status === 200)
        {
            return $response;
        }
    }
}
