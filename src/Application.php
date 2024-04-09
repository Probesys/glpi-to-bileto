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
            list($code, $response) = $this->http->get(
                $url_init_session,
                ['user_token' => $user_token],
                options: [
                    'headers' => [
                        'App-Token' => $app_token,
                    ],
                ]
            );

            if ($code !== 200) {
                echo "Cannot get a session token at {$url_init_session}: code {$code}\n";
                echo $response;
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

        $glpi_data = [];
        try {
            echo "Getting organizations…\n";
            $glpi_data['organizations'] = $this->exportEntitiesAsOrganizations();
            echo "OK\n";

            echo "Getting roles…\n";
            $glpi_data['roles'] = $this->exportProfilesAsRoles();
            echo "OK\n";

            echo "Getting users…\n";
            $glpi_data['users'] = $this->exportUsersAsUsers();
            echo "OK\n";

            echo "Getting contracts…\n";
            $glpi_data['contracts'] = $this->exportProjectTasksAsContracts();
            echo "OK\n";

            echo "Getting tickets…\n";
            $tickets = $this->getApi('/Ticket');
            foreach ($tickets as $ticket) {
                $ticket_json = $this->exportTicketAsTicket($ticket);
                $glpi_data["tickets/{$ticket_json['organizationId']}/{$ticket_json['id']}"] = $ticket_json;
            }
            echo "OK\n";
        } catch (\Exception $e) {
            echo $e->getMessage();
            return -2;
        }

        echo "Generationg the archive…\n";
        $files = [];
        foreach ($glpi_data as $name => $data) {
            $json = json_encode($data, JSON_PRETTY_PRINT);

            if ($json === false) {
                throw new \Exception('Cannot encode an array to JSON');
            }

            $files["{$name}.json"] = $json;
        }

        $now = new \DateTimeImmutable();
        $now_formatted = $now->format('Y-m-d_H\hi');
        /** @var string */
        $host = parse_url($url_base, PHP_URL_HOST);
        $filepath = "./{$now_formatted}_{$host}_data.zip";

        $zip_archive = new \ZipArchive();
        $zip_archive->open($filepath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($files as $filename => $content) {
            $zip_archive->addFromString($filename, $content);
        }

        $zip_archive->close();

        echo "OK\n";
        echo "GLPI data exported as {$filepath}";

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

    /**
     * @return array<mixed[]>
     */
    public function exportEntitiesAsOrganizations(): array
    {
        $data = $this->getApi('/Entity');

        $organizations = [];

        foreach ($data as $entity) {
            $name = $entity['completename'];

            $pos_separator = strpos($name, '>');
            if ($pos_separator !== false) {
                // Remove the "Root entity" name from the beginning of other
                // entities names.
                $name = trim(substr($name, $pos_separator + 1));
            }

            $organizations[] = [
                'id' => strval($entity['id']),
                'name' => $name,
            ];
        }

        return $organizations;
    }

    /**
     * @return array<mixed[]>
     */
    public function exportProfilesAsRoles(): array
    {
        $data = $this->getApi('/Profile');

        $roles = [];

        foreach ($data as $profile) {
            $roles[] = [
                'id' => strval($profile['id']),
                'name' => $profile['name'],
                'description' => $profile['comment'],
                'type' => '',
                'permissions' => [],
            ];
        }

        return $roles;
    }

    /**
     * @return array<mixed[]>
     */
    public function exportUsersAsUsers(): array
    {
        $data = $this->getApi('/User');

        $users = [];

        foreach ($data as $user) {
            $user_emails = $this->getApi("/User/{$user['id']}/UserEmail");
            $user_email = ArrayHelper::find($user_emails, function (array $user_email): bool {
                return $user_email['is_default'];
            });

            if ($user_email) {
                $email = $user_email['email'];
            } else {
                echo "[Warning] User {$user['id']} has no email.\n";
                $email = '';
            }

            $name = '';
            if ($user['realname'] || $user['firstname']) {
                $realname = $user['realname'] ?? '';
                $firstname = $user['firstname'] ?? '';
                $name = trim("{$firstname} {$realname}");
            } else {
                $name = $user['name'];
            }

            $ldap_identifier = null;
            if ($user['user_dn']) {
                $ldap_identifier = $user['name'];
            }

            $user_profiles = $this->getApi("/User/{$user['id']}/Profile_User");

            $authorizations = [];
            foreach ($user_profiles as $user_profile) {
                $authorizations[] = [
                    'roleId' => strval($user_profile['profiles_id']),
                    // TODO set null if entities_id = 0
                    // TODO handle tree structure?
                    'organizationId' => strval($user_profile['entities_id']),
                ];
            }

            $users[] = [
                'id' => strval($user['id']),
                'email' => $email,
                'locale' => 'fr_FR',
                'name' => $name,
                'ldapIdentifier' => $ldap_identifier,
                'organizationId' => strval($user['entities_id']),
                'authorizations' => $authorizations,
            ];
        }

        return $users;
    }

    /**
     * @return array<mixed[]>
     */
    public function exportProjectTasksAsContracts(): array
    {
        $data = $this->getApi('/Contract');
        $contracts_by_ids = [];
        foreach ($data as $contract) {
            $contracts_by_ids[$contract['id']] = $contract;
        }

        $data = $this->getApi('/Project');
        $projects_by_ids = [];
        foreach ($data as $project) {
            $projects_by_ids[$project['id']] = $project;
        }

        $data = $this->getApi('/PluginProjectbridgeContract');
        $projects_to_contracts = [];
        foreach ($data as $pb_contract) {
            $projects_to_contracts[$pb_contract['project_id']] = $pb_contract['contract_id'];
        }

        $data = $this->getApi('/ProjectTask');

        $contracts = [];

        foreach ($data as $project_task) {
            if (!isset($projects_by_ids[$project_task['projects_id']])) {
                echo "[Warning] Skipping project task {$project_task['id']}: ";
                echo "project {$project_task['projects_id']} doesn't exist\n";
                continue;
            }

            $project = $projects_by_ids[$project_task['projects_id']];

            if (!isset($projects_to_contracts[$project['id']])) {
                echo "[Warning] Skipping project task {$project_task['id']}: ";
                echo "contract for project {$project['id']} doesn't exist\n";
                continue;
            }

            $contract_id = $projects_to_contracts[$project['id']];

            if (!isset($contracts_by_ids[$contract_id])) {
                echo "[Warning] Skipping project task {$project_task['id']}: ";
                echo "contract {$contract_id} doesn't exist\n";
                continue;
            }

            $contract = $contracts_by_ids[$contract_id];

            $start_at = new \DateTimeImmutable($project_task['plan_start_date']);
            $end_at = new \DateTimeImmutable($project_task['plan_end_date']);
            $date_alert = $contract['notice'] * 30;

            $name = $project['name'] . ' - ' . $project_task['name'];

            $contracts[] = [
                'id' => strval($project_task['id']),
                'name' => $name,
                'startAt' => $start_at->format(\DateTimeInterface::RFC3339),
                'endAt' => $end_at->format(\DateTimeInterface::RFC3339),
                'maxHours' => intval($project_task['planned_duration'] / 60 / 60),
                'notes' => $contract['comment'],
                'organizationId' => strval($contract['entities_id']),
                'timeAccountingUnit' => 30,
                'hoursAlert' => 80, // TODO import from project bridge
                'dateAlert' => $date_alert,
            ];
        }

        return $contracts;
    }

    /**
     * @param mixed[] $ticket
     *
     * @return mixed[]
     */
    public function exportTicketAsTicket(array $ticket): array
    {
        $messages = [];
        $messages[] = $this->exportTicketAsMessage($ticket);

        $created_at = new \DateTimeImmutable($ticket['date']);

        $requester_id = null;
        $assignee_id = null;
        $observers_ids = []; // not supported by Bileto yet

        $ticket_users = $this->getApi("/Ticket/{$ticket['id']}/Ticket_User");
        foreach ($ticket_users as $ticket_user) {
            // TODO How to handle multiple requesters or assignees?
            if ($ticket_user['type'] === 1) {
                $requester_id = strval($ticket_user['users_id']);
            } elseif ($ticket_user['type'] === 2) {
                $assignee_id = strval($ticket_user['users_id']);
            } elseif ($ticket_user['type'] === 3) {
                $observers_ids[] = strval($ticket_user['users_id']);
            }
        }

        if ($ticket['type'] === 1) {
            $type = 'incident';
        } else {
            $type = 'request';
        }

        if ($ticket['status'] === 1) {
            $status = 'new';
        } elseif ($ticket['status'] === 2) {
            $status = 'in_progress';
        } elseif ($ticket['status'] === 3) {
            $status = 'planned';
        } elseif ($ticket['status'] === 4) {
            $status = 'pending';
        } elseif ($ticket['status'] === 5) {
            $status = 'resolved';
        } else {
            $status = 'closed';
        }

        $itil_solutions = $this->getApi("/Ticket/{$ticket['id']}/ITILSolution");
        $itil_solution = ArrayHelper::find($itil_solutions, function (array $itil_solution): bool {
            return $itil_solution['status'] === 3; // TODO get pending solution?
        });
        $solution_id = null;
        if ($itil_solution) {
            $solution_id = 'solution-' . $itil_solution['id'];
        }

        foreach ($itil_solutions as $itil_solution) {
            $messages[] = $this->exportItilSolutionAsMessage($itil_solution);
        }

        // TODO load PluginProjectbridgeTicket instead?
        $project_tasks = $this->getApi("/Ticket/{$ticket['id']}/ProjectTask");
        $contract_ids = [];
        foreach ($project_tasks as $project_task) {
            $contract_ids[] = strval($project_task['id']);
        }

        $contract_id = $contract_ids[0] ?? null;

        $ticket_tasks = $this->getApi("/Ticket/{$ticket['id']}/TicketTask");
        $time_spents = [];
        foreach ($ticket_tasks as $ticket_task) {
            $task_created_at = new \DateTimeImmutable($ticket_task['date']);
            $time = intval($ticket_task['actiontime'] / 60);

            $time_spents[] = [
                'createdAt' => $task_created_at->format(\DateTimeInterface::RFC3339),
                'createdById' => strval($ticket_task['users_id']),
                'time' => $time,
                'realTime' => $time,
                'contractId' => $contract_id,
            ];

            $messages[] = $this->exportTicketTaskAsMessage($ticket_task);
        }

        $itil_followups = $this->getApi("/Ticket/{$ticket['id']}/ITILFollowup");
        foreach ($itil_followups as $itil_followup) {
            $messages[] = $this->exportItilFollowupAsMessage($itil_followup);
        }

        return [
            'id' => strval($ticket['id']),
            'createdAt' => $created_at->format(\DateTimeInterface::RFC3339),
            'createdById' => strval($ticket['users_id_recipient']),
            'type' => $type,
            'status' => $status,
            'title' => $ticket['name'],
            'urgency' => $this->getWeight($ticket['urgency']),
            'impact' => $this->getWeight($ticket['impact']),
            'priority' => $this->getWeight($ticket['priority']),
            'requesterId' => $requester_id,
            'assigneeId' => $assignee_id,
            'organizationId' => strval($ticket['entities_id']),
            'solutionId' => $solution_id,
            'contractIds' => $contract_ids,
            'timeSpents' => $time_spents,
            'messages' => $messages,
        ];
    }

    /**
     * @param mixed[] $ticket
     *
     * @return mixed[]
     */
    public function exportTicketAsMessage(array $ticket): array
    {
        $created_at = new \DateTimeImmutable($ticket['date']);

        $request_types = $this->getApi("/Ticket/{$ticket['id']}/RequestType");
        $via = 'webapp';
        if (count($request_types) > 1 && $request_types[0]['name'] === 'Email') {
            // TODO don't work
            $via = 'email';
        }

        $document_items = $this->getApi("/Ticket/{$ticket['id']}/Document_Item");
        $message_documents = $this->exportDocumentItemsToMessageDocuments($document_items);

        return [
            'id' => "ticket-{$ticket['id']}",
            'createdAt' => $created_at->format(\DateTimeInterface::RFC3339),
            'createdById' => strval($ticket['users_id_recipient']),
            'isConfidential' => false,
            'via' => $via,
            'content' => $ticket['content'],
            'messageDocuments' => $message_documents,
        ];
    }

    /**
     * @param mixed[] $itil_solution
     *
     * @return mixed[]
     */
    public function exportItilSolutionAsMessage(array $itil_solution): array
    {
        $created_at = new \DateTimeImmutable($itil_solution['date_creation']);

        $document_items = $this->getApi("/ITILSolution/{$itil_solution['id']}/Document_Item");
        $message_documents = $this->exportDocumentItemsToMessageDocuments($document_items);

        return [
            'id' => "solution-{$itil_solution['id']}",
            'createdAt' => $created_at->format(\DateTimeInterface::RFC3339),
            'createdById' => strval($itil_solution['users_id']),
            'isConfidential' => false,
            'via' => 'webapp',
            'content' => $itil_solution['content'],
            'messageDocuments' => $message_documents,
        ];
    }

    /**
     * @param mixed[] $ticket_task
     *
     * @return mixed[]
     */
    public function exportTicketTaskAsMessage(array $ticket_task): array
    {
        $created_at = new \DateTimeImmutable($ticket_task['date']);

        $document_items = $this->getApi("/TicketTask/{$ticket_task['id']}/Document_Item");
        $message_documents = $this->exportDocumentItemsToMessageDocuments($document_items);

        return [
            'id' => "ticket-task-{$ticket_task['id']}",
            'createdAt' => $created_at->format(\DateTimeInterface::RFC3339),
            'createdById' => strval($ticket_task['users_id']),
            'isConfidential' => $ticket_task['is_private'] === 1,
            'via' => 'webapp',
            'content' => $ticket_task['content'],
            'messageDocuments' => $message_documents,
        ];
    }

    /**
     * @param mixed[] $itil_followup
     *
     * @return mixed[]
     */
    public function exportItilFollowupAsMessage(array $itil_followup): array
    {
        $created_at = new \DateTimeImmutable($itil_followup['date']);

        $request_types = $this->getApi("/ITILFollowup/{$itil_followup['id']}/RequestType");
        $via = 'webapp';
        if (count($request_types) > 1 && $request_types[0]['name'] === 'Email') {
            // TODO don't work
            $via = 'email';
        }

        $document_items = $this->getApi("/ITILFollowup/{$itil_followup['id']}/Document_Item");
        $message_documents = $this->exportDocumentItemsToMessageDocuments($document_items);

        return [
            'id' => "followup-{$itil_followup['id']}",
            'createdAt' => $created_at->format(\DateTimeInterface::RFC3339),
            'createdById' => strval($itil_followup['users_id']),
            'isConfidential' => $itil_followup['is_private'] === 1,
            'via' => $via,
            'content' => $itil_followup['content'],
            'messageDocuments' => $message_documents,
        ];
    }

    /**
     * @param mixed[] $document_items
     *
     * @return mixed[]
     */
    public function exportDocumentItemsToMessageDocuments(array $document_items): array
    {
        $message_documents = [];

        foreach ($document_items as $document_item) {
            $document = $this->getApi("/Document/{$document_item['documents_id']}");
            $message_documents[] = [
                'name' => $document['name'],
                'filepath' => $document['filepath'],
            ];
        }

        return $message_documents;
    }

    private function getWeight(int $glpi_weight): string
    {
        if ($glpi_weight < 3) {
            return 'low';
        } elseif ($glpi_weight > 3) {
            return 'high';
        } else {
            return 'medium';
        }
    }

    /**
     * @param array<string, mixed> $parameters
     * @return mixed[]
     */
    private function getApi(string $endpoint, array $parameters = []): array
    {
        $url = $this->url_api . $endpoint;
        list($code, $response) = $this->http->get($url, $parameters);

        if ($code !== 200 && $code !== 206) {
            throw new \Exception("Cannot get URL {$url}: code {$code}\n{$response}");
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \Exception("Cannot get URL {$url}: invalid JSON\n{$response}");
        }

        return $data;
    }
}
