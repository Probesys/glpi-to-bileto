<?php

// This file is part of GLPI To Bileto.
// Copyright 2024 Probesys
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App;

/**
 * @phpstan-type AppConfiguration array{
 *     app_path: string,
 * }
 */
class Application
{
    public string $app_path;

    public Http $http;

    public string $url_api;

    /**
     * @param AppConfiguration $configuration
     **/
    public function __construct(array $configuration)
    {
        $this->app_path = $configuration['app_path'];
        $this->http = new Http();
    }

    /**
     * Create a Request reading the CLI arguments.
     *
     * @param non-empty-list<string> $arguments
     */
    public function execute(array $arguments): int
    {
        if (count($arguments) !== 2) {
            echo $this->usage();
            return -1;
        }

        $url_base = $arguments[1];
        $url_base = trim($url_base, '/');

        if (!$this->isValidUrl($url_base)) {
            echo "{$url_base} is not a valid URL.";
            return -1;
        }

        $this->url_api = "{$url_base}/apirest.php";

        list (
            $app_token,
            $user_token,
            $session_token,
        ) = $this->loadTokens();

        if (!$app_token || !$user_token) {
            $this->askTokens();

            list (
                $app_token,
                $user_token,
                $session_token,
            ) = $this->loadTokens();

            assert($app_token !== null);
            assert($user_token !== null);
        }

        if (!$session_token) {
            $url_init_session = "{$this->url_api}/initSession";
            list($code, $response) = $this->http->get($url_init_session, options: [
                'headers' => [
                    'Authorization' => "user_token {$user_token}",
                    'App-Token' => $app_token,
                ],
            ]);

            if ($code !== 200) {
                echo "Cannot get a session token at {$url_init_session}: code {$code}";
                return -1;
            }

            $data = json_decode($response, true);
            if (!is_array($data)) {
                echo "Cannot get a session token at {$url_init_session}: invalid JSON\n{$response}";
                return -1;
            }

            if (!isset($data['session_token']) || !is_string($data['session_token'])) {
                $data = print_r($data, true);
                echo "Cannot get a session token at {$url_init_session}:\n{$data}";
                return -1;
            }

            $session_token = $data['session_token'];
            $this->storeSessionToken($session_token);
        }

        $this->http->headers['Session-Token'] = $session_token;
        $this->http->headers['App-Token'] = $app_token;

        $url_entities = "{$this->url_api}/Entity";
        $response = $this->http->get($url_entities);
        echo json_encode($response, JSON_PRETTY_PRINT);

        return 0;
    }

    public function usage(): string
    {
        return <<<TEXT
        Usage: php bin/glpi-export URL

        URL must be a valid URL to a GLPI server.
        TEXT;
    }

    public function isValidUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $url_components = parse_url($url);

        return (
            $url_components &&
            isset($url_components['scheme']) &&
            isset($url_components['host']) &&
            in_array(strtolower($url_components['scheme']), ['http', 'https'])
        );
    }

    /**
     * @return array{?string, ?string, ?string}
     */
    public function loadTokens(): array
    {
        $dotenv = new Dotenv("{$this->app_path}/.env");
        return [
           $dotenv->pop('APP_TOKEN'),
           $dotenv->pop('USER_TOKEN'),
           $dotenv->pop('SESSION_TOKEN'),
        ];
    }

    public function askTokens(): void
    {
        $stdin = fopen('php://stdin', 'r');

        if ($stdin === false) {
            throw new \RuntimeException('Cannot open stdin stream.');
        }

        echo "App Token: ";
        $app_token = trim(fgets($stdin) ?: '');
        echo "User Token: ";
        $user_token = trim(fgets($stdin) ?: '');

        file_put_contents("{$this->app_path}/.env", <<<TEXT
            APP_TOKEN = '{$app_token}'
            USER_TOKEN = '{$user_token}'
            TEXT);
    }

    public function storeSessionToken(string $session_token): void
    {
        file_put_contents(
            "{$this->app_path}/.env",
            "\nSESSION_TOKEN = '{$session_token}'",
            FILE_APPEND
        );
    }
}
