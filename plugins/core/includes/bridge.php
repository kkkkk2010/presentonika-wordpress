<?php
if (!defined('ABSPATH')) { exit; }

function pnk_request_bridge_outzip_from_pptx_url(string $pptxUrl) {
    $resp = wp_remote_post(
        pnk_editor_base() . '/api/bridge/convert-from-url',
        [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . (string)PRESENTONIKA_BRIDGE_TOKEN,
            ],
            'body'    => wp_json_encode(['pptxUrl' => $pptxUrl]),
            'timeout' => 240,
        ]
    );

    if (is_wp_error($resp)) {
        pnk_log('bridge.convert_request_failed', ['error_code' => $resp->get_error_code()], 'error');
        return new WP_Error('bridge_request_failed', 'Bridge request failed');
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $body = (string) wp_remote_retrieve_body($resp);
    $json = json_decode($body, true);

    if ($code < 200 || $code >= 300 || !is_array($json) || empty($json['outZipUrl'])) {
        pnk_log('bridge.convert_bad_response', ['http_status' => $code], 'error');
        return new WP_Error('bridge_bad_response', 'Bridge returned an invalid response');
    }

    return $json;
}

function pnk_request_bridge_job_from_outzip_url(string $outZipUrl, array $launchContext = []) {
    $requestBody = array_merge(['outZipUrl' => $outZipUrl], $launchContext);
    $resp = wp_remote_post(
        pnk_editor_base() . '/api/bridge/import-outzip-from-url',
        [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . (string)PRESENTONIKA_BRIDGE_TOKEN,
            ],
            'body'    => wp_json_encode($requestBody),
            'timeout' => 240,
        ]
    );

    if (is_wp_error($resp)) {
        pnk_log('bridge.import_request_failed', ['error_code' => $resp->get_error_code()], 'error');
        return new WP_Error('bridge_import_request_failed', 'Bridge import request failed');
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $body = (string) wp_remote_retrieve_body($resp);
    $json = json_decode($body, true);

    if ($code < 200 || $code >= 300 || !is_array($json) || (empty($json['launchUrl']) && empty($json['outZipUrl']))) {
        pnk_log('bridge.import_bad_response', ['http_status' => $code], 'error');
        return new WP_Error('bridge_import_bad_response', 'Bridge import returned an invalid response');
    }

    return $json;
}
