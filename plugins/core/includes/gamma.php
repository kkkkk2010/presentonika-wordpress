<?php
if (!defined('ABSPATH')) { exit; }

class PNK_Gamma_API {
    private string $baseUrl = 'https://public-api.gamma.app/v1.0/';

    public function createPresentation(string $text, string $theme) {
        if (!defined('GAMMA_API_KEY') || !GAMMA_API_KEY) return false;

        $data = [
            'inputText' => $text,
            'textMode'  => 'generate',
            'format'    => 'presentation',
            'exportAs'  => 'pptx',
            'textOptions' => [
                'language' => 'ru',
                'amount'   => 'detailed',
                'tone'     => 'professional'
            ],
            'imageOptions' => [
                'source' => 'aiGenerated',
                'style'  => $theme
            ]
        ];

        $response = wp_remote_post(
            $this->baseUrl . 'generations',
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-API-KEY'    => GAMMA_API_KEY,
                ],
                'body'    => wp_json_encode($data),
                'timeout' => 30,
            ]
        );

        if (is_wp_error($response)) return false;

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) return false;

        $json = json_decode($body, true);
        return is_array($json) ? $json : false;
    }

    public function getResult(string $generationId) {
        if (!defined('GAMMA_API_KEY') || !GAMMA_API_KEY) return false;

        $response = wp_remote_get(
            $this->baseUrl . 'generations/' . rawurlencode($generationId),
            [
                'headers' => ['X-API-KEY' => GAMMA_API_KEY],
                'timeout' => 20,
            ]
        );

        if (is_wp_error($response)) return false;

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) return false;

        $json = json_decode($body, true);
        return is_array($json) ? $json : false;
    }
}
