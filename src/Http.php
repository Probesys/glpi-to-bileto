<?php

// This file is part of GLPI To Bileto.
// Copyright 2024 Probesys
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App;

/**
 * @phpstan-type Options array{
 *     'headers'?: array<string, string>,
 * }
 */
class Http
{
    public int $timeout = 5;

    public string $user_agent = 'GLPIToBileto';

    /** @var array<string, string> */
    public array $headers = [
        'Content-Type' => 'application/json',
    ];

    /**
     * Make a GET HTTP request.
     *
     * @param array<string, mixed> $parameters
     * @param Options $options
     *
     * @throws \RuntimeException
     *
     * @return array{int, string}
     */
    public function get(string $url, array $parameters = [], array $options = []): array
    {
        if ($parameters) {
            $parameters_query = http_build_query($parameters);
            if (strpos($url, '?') === false) {
                $url .= '?' . $parameters_query;
            } else {
                $url .= '&' . $parameters_query;
            }
        }

        $curl_handle = curl_init();
        curl_setopt($curl_handle, CURLOPT_URL, $url);
        curl_setopt($curl_handle, CURLOPT_HEADER, false);
        curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl_handle, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl_handle, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($curl_handle, CURLOPT_USERAGENT, $this->user_agent);

        if (isset($options['headers'])) {
            $headers = array_merge($this->headers, $options['headers']);
        } else {
            $headers = $this->headers;
        }

        $request_headers = [];
        foreach ($headers as $name => $value) {
            $request_headers[] = "{$name}: {$value}";
        }
        curl_setopt($curl_handle, CURLOPT_HTTPHEADER, $request_headers);

        /** @var string|false */
        $data = curl_exec($curl_handle);
        $status_code = curl_getinfo($curl_handle, CURLINFO_RESPONSE_CODE);

        if ($data === false) {
            $error = curl_error($curl_handle);

            curl_close($curl_handle);

            throw new \RuntimeException($error);
        }

        curl_close($curl_handle);

        return [$status_code, $data];
    }
}
