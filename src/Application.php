<?php

// This file is part of GLPI To Bileto.
// Copyright 2024 Probesys
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App;

class Application
{
    private Database $database;

    /**
     * @var array{
     *     'merge organizations': bool,
     *     'ignore contracts': bool,
     *     'since': ?\DateTimeImmutable,
     * } $options
     */
    private array $options;

    /** @var array<int, string> */
    private array $entities_to_orgas;

    public function __construct(string $app_path)
    {
        $dotenv = new Dotenv("{$app_path}/.env");

        $this->database = Database::get([
            'host' => $dotenv->pop('DB_HOST', 'localhost'),
            'port' => intval($dotenv->pop('DB_PORT', '3306')),
            'dbname' => $dotenv->pop('DB_NAME', 'glpi'),
            'username' => $dotenv->pop('DB_USERNAME', 'mariadb'),
            'password' => $dotenv->pop('DB_PASSWORD', 'mariadb'),
        ]);
    }

    /**
     * Execute the command with args coming from the command line.
     *
     * @param string[] $arguments
     */
    public function execute(array $arguments): int
    {
        $this->options = [
            'merge organizations' => false,
            'ignore contracts' => false,
            'since' => null,
        ];

        $this->entities_to_orgas = [];

        foreach ($arguments as $argument) {
            if ($argument === '--help' || $argument === '-h') {
                echo $this->usage();
                return 0;
            } elseif ($argument === '--merge-organizations') {
                $this->options['merge organizations'] = true;
            } elseif ($argument === '--ignore-contracts') {
                $this->options['ignore contracts'] = true;
            } elseif (str_starts_with($argument, '--since=')) {
                $date_string = substr($argument, strlen('--since='));

                try {
                    $date = new \DateTimeImmutable($date_string);
                    $this->options['since'] = $date;
                } catch (\Exception $error) {
                    echo "Malformed option: --since argument must be a valid datetime (e.g. 2024-01-01)";
                    return -1;
                }
            } else {
                echo "Unrecognized option: {$argument}\n\n";
                echo $this->usage();
                return -1;
            }
        }

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

            if (!$this->options['ignore contracts']) {
                echo "Getting contracts…\n";
                $glpi_data['contracts'] = $this->exportProjectTasksAsContracts();
                echo "OK\n";
            }

            echo "Getting labels…\n";
            $glpi_data['labels'] = $this->exportCategoriesAsLabels();
            echo "OK\n";

            echo "Getting tickets…\n";
            $tickets = $this->exportTicketsAsTickets();
            foreach ($tickets as $ticket) {
                $glpi_data["tickets/{$ticket['organizationId']}/{$ticket['id']}"] = $ticket;
            }
            echo "OK\n";
        } catch (\Exception $e) {
            echo '[Critical] ' . $e->getMessage();
            return -2;
        }

        echo "Generating the archive…\n";
        $files = [];
        foreach ($glpi_data as $name => $data) {
            try {
                $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                $files["{$name}.json"] = $json;
            } catch (\JsonException $error) {
                echo "[Critical] Cannot export the {$name}.json file: ";
                echo "encoding the data to JSON failed ({$error->getMessage()})";
                return -2;
            }
        }

        $now = new \DateTimeImmutable();
        $now_formatted = $now->format('Y-m-d_H\hi');
        $filepath = "./{$now_formatted}_glpi_data.zip";

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

    /**
     * Return a string explaining how to use the command line.
     */
    public function usage(): string
    {
        return <<<TEXT
        Usage: php bin/glpi-export [OPTION]
        Export the data of a GLPI database into a ZIP archive that can be imported into a
        Bileto server.

        Options:
          --help -h                  display this help message
          --merge-organizations      merge the organizations having the same name
          --ignore-contracts         don’t try to load contracts from ProjectBridge
          --since=[YYYY-MM-DD]       export tickets and contracts after the given date
        TEXT;
    }

    /**
     * Export GLPI Entities to Bileto Organizations.
     *
     * @return array<mixed[]>
     */
    public function exportEntitiesAsOrganizations(): array
    {
        $data = $this->database->fetchAll(<<<SQL
            SELECT id, name
            FROM glpi_entities
        SQL);

        $organizations = [];
        $names_to_ids = [];

        foreach ($data as $entity) {
            $entity_id = intval($entity['id']);
            $organization_id = strval($entity_id);
            $name = $entity['name'];

            if ($this->options['merge organizations'] && isset($names_to_ids[$name])) {
                $organization_id = $names_to_ids[$name];
            } else {
                $organizations[] = [
                    'id' => $organization_id,
                    'name' => $name,
                ];

                $names_to_ids[$name] = $organization_id;
            }

            $this->entities_to_orgas[$entity_id] = $organization_id;
        }

        return $organizations;
    }

    /**
     * Export GLPI Profiles to Bileto Roles.
     *
     * @return array<mixed[]>
     */
    public function exportProfilesAsRoles(): array
    {
        $data = $this->database->fetchAll(<<<SQL
            SELECT id, name, comment
            FROM glpi_profiles
        SQL);

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
     * Export GLPI Users to Bileto Users.
     *
     * @return array<mixed[]>
     */
    public function exportUsersAsUsers(): array
    {
        $data = $this->database->fetchAll(<<<SQL
            SELECT id, name, realname, firstname, entities_id, user_dn
            FROM glpi_users
        SQL);

        $users = [];

        foreach ($data as $user) {
            $email = $this->database->fetchValue(<<<SQL
                SELECT email
                FROM glpi_useremails
                WHERE users_id = :user_id
                AND is_default = true
            SQL, [
                ':user_id' => $user['id'],
            ]);

            $name = '';
            if ($user['realname'] || $user['firstname']) {
                $realname = $user['realname'] ?? '';
                $firstname = $user['firstname'] ?? '';
                $name = trim("{$firstname} {$realname}");
            } else {
                $name = $user['name'];
            }

            if (!$email) {
                echo "[Error] User {$name} (id {$user['id']}) is invalid: email is missing\n";
                $email = '';
            }

            $ldap_identifier = null;
            if ($user['user_dn']) {
                $ldap_identifier = $user['name'];
            }

            $user_profiles = $this->database->fetchAll(<<<SQL
                SELECT id, profiles_id, entities_id, is_recursive
                FROM glpi_profiles_users
                WHERE users_id = :user_id
            SQL, [
                ':user_id' => $user['id'],
            ]);

            $authorizations = [];
            foreach ($user_profiles as $user_profile) {
                $context = "User Profile (id {$user_profile['id']}) of User {$name} (id {$user['id']})";

                if ($user_profile['is_recursive']) {
                    echo "[Warning] Skipping {$context}: no support for GLPI recursive profiles\n";
                    continue;
                }

                $authorizations[] = [
                    'roleId' => strval($user_profile['profiles_id']),
                    'organizationId' => $this->getOrganizationId($user_profile['entities_id'], context: $context),
                ];
            }

            $context = "User {$name} (id {$user['id']})";

            $users[] = [
                'id' => strval($user['id']),
                'email' => $email,
                'locale' => 'fr_FR',
                'name' => $name,
                'ldapIdentifier' => $ldap_identifier,
                'organizationId' => $this->getOrganizationId($user['entities_id'], context: $context),
                'authorizations' => $authorizations,
            ];
        }

        return $users;
    }

    /**
     * Export GLPI ProjectTasks to Bileto Contracts.
     *
     * Note that it's based on the data produced by our plugin ProjectBridge
     *
     * @see https://github.com/Probesys/glpi-plugins-projectbridge
     *
     * @return array<mixed[]>
     */
    public function exportProjectTasksAsContracts(): array
    {
        $contracts_by_ids = $this->database->fetchIndexed(<<<SQL
            SELECT id, notice, comment, entities_id
            FROM glpi_contracts
        SQL);

        $projects_by_ids = $this->database->fetchIndexed(<<<SQL
            SELECT id, name
            FROM glpi_projects
        SQL);

        $projects_to_contracts = $this->database->fetchKeyValue(<<<SQL
            SELECT project_id, contract_id
            FROM glpi_plugin_projectbridge_contracts
        SQL);

        $sql = <<<SQL
            SELECT id, projects_id, plan_start_date, plan_end_date, name, planned_duration
            FROM glpi_projecttasks
        SQL;
        $parameters = [];

        $since = $this->options['since'];
        if ($since) {
            $sql .= ' WHERE plan_end_date >= :since';
            $parameters[':since'] = $since->format('Y-m-d');
        }

        $statement = $this->database->prepare($sql);
        $statement->execute($parameters);
        $data = $statement->fetchAll();

        $contracts = [];

        foreach ($data as $project_task) {
            $project_id = $project_task['projects_id'];

            if (!isset($projects_by_ids[$project_id])) {
                echo "[Warning] Skipping Project Task (id {$project_task['id']}): ";
                echo "its related Project (id {$project_id}) doesn't exist\n";
                continue;
            }

            $project = $projects_by_ids[$project_id];

            if (!isset($projects_to_contracts[$project_id])) {
                echo "[Warning] Skipping Project Task (id {$project_task['id']}): ";
                echo "its related Project (id {$project_id}) is not attached to a Contract\n";
                continue;
            }

            $contract_id = $projects_to_contracts[$project_id];

            if (!isset($contracts_by_ids[$contract_id])) {
                echo "[Warning] Skipping Project Task (id {$project_task['id']}): ";
                echo "its related Project (id {$project_id}) is attached to an unknown Contract (id {$contract_id})\n";
                continue;
            }

            if (!isset($project_task['plan_start_date'])) {
                echo "[Warning] Skipping Project Task (id {$project_task['id']}): ";
                echo "the plan_start_date field is not set\n";
                continue;
            }

            if (!isset($project_task['plan_end_date'])) {
                echo "[Warning] Skipping Project Task (id {$project_task['id']}): ";
                echo "the plan_end_date field is not set\n";
                continue;
            }

            $contract = $contracts_by_ids[$contract_id];

            $start_at = new \DateTimeImmutable($project_task['plan_start_date']);
            $end_at = new \DateTimeImmutable($project_task['plan_end_date']);
            $date_alert = $contract['notice'] * 30;

            $name = $project['name'] . ' - ' . $project_task['name'];

            $hours_alert = $this->database->fetchValue(<<<SQL
                SELECT quotaAlert
                FROM glpi_plugin_projectbridge_contracts_quotaAlert
                WHERE contract_id = :contract_id
            SQL, [
                ':contract_id' => $contract_id,
            ]);

            if (!$hours_alert) {
                $hours_alert = 80;
            }

            $context = "Contract (id {$contract_id})";

            $contracts[] = [
                'id' => strval($project_task['id']),
                'name' => $name,
                'startAt' => $start_at->format(\DateTimeInterface::RFC3339),
                'endAt' => $end_at->format(\DateTimeInterface::RFC3339),
                'maxHours' => intval($project_task['planned_duration'] / 60 / 60),
                'notes' => $contract['comment'],
                'organizationId' => $this->getOrganizationId($contract['entities_id'], context: $context),
                'timeAccountingUnit' => 30,
                'hoursAlert' => $hours_alert,
                'dateAlert' => $date_alert,
            ];
        }

        return $contracts;
    }

    /**
     * Export GLPI Categories to Bileto Labels.
     *
     * @return array<mixed[]>
     */
    public function exportCategoriesAsLabels(): array
    {
        $data = $this->database->fetchAll(<<<SQL
            SELECT id, completename, comment
            FROM glpi_itilcategories
        SQL);

        $labels = [];

        foreach ($data as $category) {
            $labels[] = [
                'id' => strval($category['id']),
                'name' => $category['completename'],
                'description' => $category['comment'],
                'color' => 'grey',
            ];
        }

        return $labels;
    }

    /**
     * Export GLPI Tickets to Bileto Tickets.
     *
     * @return array<mixed[]>
     */
    public function exportTicketsAsTickets(): array
    {
        $sql = <<<SQL
            SELECT id, date, users_id_recipient, name, content, type, status,
                urgency, impact, priority, entities_id, requesttypes_id,
                itilcategories_id
            FROM glpi_tickets
        SQL;
        $parameters = [];

        $since = $this->options['since'];
        if ($since) {
            $sql .= ' WHERE date >= :since';
            $parameters[':since'] = $since->format('Y-m-d');
        }

        $statement = $this->database->prepare($sql);
        $statement->execute($parameters);
        $data = $statement->fetchAll();

        $tickets = [];
        foreach ($data as $ticket) {
            $messages = [];
            $messages[] = $this->exportTicketAsMessage($ticket);

            $created_at = new \DateTimeImmutable($ticket['date']);

            list(
                $requester_id,
                $assignee_id,
                $observer_ids,
            ) = $this->fetchTicketActors($ticket);

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

            $itil_solutions = $this->database->fetchAll(<<<SQL
                SELECT id, status, date_creation, users_id, content
                FROM glpi_itilsolutions
                WHERE items_id = :ticket_id
                AND itemtype = 'Ticket'
            SQL, [
                ':ticket_id' => $ticket['id'],
            ]);

            $itil_solution = ArrayHelper::find($itil_solutions, function (array $itil_solution): bool {
                return $itil_solution['status'] === 2 || $itil_solution['status'] === 3;
            });
            $solution_id = null;
            if ($itil_solution) {
                $solution_id = 'solution-' . $itil_solution['id'];
            }

            foreach ($itil_solutions as $itil_solution) {
                if (isset($itil_solution['content'])) {
                    $messages[] = $this->exportItilSolutionAsMessage($itil_solution);
                }
            }

            if ($this->options['ignore contracts']) {
                $contract_ids = [];
            } else {
                // TODO load PluginProjectbridgeTicket instead?
                $ticket_project_tasks = $this->database->fetchAll(<<<SQL
                    SELECT projecttasks_id
                    FROM glpi_projecttasks_tickets
                    WHERE tickets_id = :ticket_id
                SQL, [
                    ':ticket_id' => $ticket['id'],
                ]);
                $contract_ids = array_map(function (array $ticket_project_task): string {
                    return strval($ticket_project_task['projecttasks_id']);
                }, $ticket_project_tasks);
            }

            $contract_id = $contract_ids[0] ?? null;

            $label_ids = [];
            if ($ticket['itilcategories_id'] > 0) {
                $label_ids[] = strval($ticket['itilcategories_id']);
            }

            $ticket_tasks = $this->database->fetchAll(<<<SQL
                SELECT id, date, actiontime, users_id, is_private, content
                FROM glpi_tickettasks
                WHERE tickets_id = :ticket_id
            SQL, [
                ':ticket_id' => $ticket['id'],
            ]);

            $time_spents = [];
            foreach ($ticket_tasks as $ticket_task) {
                $time_spent = $this->exportTicketTaskAsTimeSpent($ticket_task);
                $time_spent['contractId'] = $contract_id;
                $time_spents[] = $time_spent;

                if (isset($ticket_task['content'])) {
                    $messages[] = $this->exportTicketTaskAsMessage($ticket_task);
                }
            }

            $itil_followups = $this->database->fetchAll(<<<SQL
                SELECT id, date, users_id, is_private, content, requesttypes_id
                FROM glpi_itilfollowups
                WHERE itemtype = 'Ticket'
                AND items_id = :ticket_id
            SQL, [
                ':ticket_id' => $ticket['id'],
            ]);
            foreach ($itil_followups as $itil_followup) {
                if (isset($itil_followup['content'])) {
                    $messages[] = $this->exportItilFollowupAsMessage($itil_followup);
                }
            }

            $context = "Ticket (id {$ticket['id']})";

            $tickets[] = [
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
                'observerIds' => $observer_ids,
                'organizationId' => $this->getOrganizationId($ticket['entities_id'], context: $context),
                'solutionId' => $solution_id,
                'contractIds' => $contract_ids,
                'labelIds' => $label_ids,
                'timeSpents' => $time_spents,
                'messages' => $messages,
            ];
        }

        return $tickets;
    }

    /**
     * Export a GLPI Ticket to a Bileto Message.
     *
     * @param mixed[] $ticket
     *
     * @return mixed[]
     */
    public function exportTicketAsMessage(array $ticket): array
    {
        $created_at = new \DateTimeImmutable($ticket['date']);
        $via = $this->fetchVia($ticket['requesttypes_id']);
        $document_items = $this->fetchDocumentItems('Ticket', $ticket['id']);
        $message_documents = $this->exportDocumentItemsToMessageDocuments($document_items);
        $content = html_entity_decode($ticket['content'] ?? '');

        return [
            'id' => "ticket-{$ticket['id']}",
            'createdAt' => $created_at->format(\DateTimeInterface::RFC3339),
            'createdById' => strval($ticket['users_id_recipient']),
            'isConfidential' => false,
            'via' => $via,
            'content' => $content,
            'messageDocuments' => $message_documents,
        ];
    }

    /**
     * Export a GLPI ITILSolution to a Bileto Message.
     *
     * @param mixed[] $itil_solution
     *
     * @return mixed[]
     */
    public function exportItilSolutionAsMessage(array $itil_solution): array
    {
        $created_at = new \DateTimeImmutable($itil_solution['date_creation']);
        $document_items = $this->fetchDocumentItems('ITILSolution', $itil_solution['id']);
        $message_documents = $this->exportDocumentItemsToMessageDocuments($document_items);
        $content = html_entity_decode($itil_solution['content']);

        return [
            'id' => "solution-{$itil_solution['id']}",
            'createdAt' => $created_at->format(\DateTimeInterface::RFC3339),
            'createdById' => strval($itil_solution['users_id']),
            'isConfidential' => false,
            'via' => 'webapp',
            'content' => $content,
            'messageDocuments' => $message_documents,
        ];
    }

    /**
     * Export a GLPI TicketTask to a Bileto SpentTime.
     *
     * @param mixed[] $ticket_task
     *
     * @return mixed[]
     */
    public function exportTicketTaskAsTimeSpent(array $ticket_task): array
    {
        $task_created_at = new \DateTimeImmutable($ticket_task['date']);
        $time = intval($ticket_task['actiontime'] / 60);
        return [
            'createdAt' => $task_created_at->format(\DateTimeInterface::RFC3339),
            'createdById' => strval($ticket_task['users_id']),
            'time' => $time,
            'realTime' => $time,
        ];
    }

    /**
     * Export a GLPI TicketTask to a Bileto Message.
     *
     * @param mixed[] $ticket_task
     *
     * @return mixed[]
     */
    public function exportTicketTaskAsMessage(array $ticket_task): array
    {
        $created_at = new \DateTimeImmutable($ticket_task['date']);
        $document_items = $this->fetchDocumentItems('TicketTask', $ticket_task['id']);
        $message_documents = $this->exportDocumentItemsToMessageDocuments($document_items);
        $content = html_entity_decode($ticket_task['content']);

        return [
            'id' => "ticket-task-{$ticket_task['id']}",
            'createdAt' => $created_at->format(\DateTimeInterface::RFC3339),
            'createdById' => strval($ticket_task['users_id']),
            'isConfidential' => $ticket_task['is_private'] === 1,
            'via' => 'webapp',
            'content' => $content,
            'messageDocuments' => $message_documents,
        ];
    }

    /**
     * Export a GLPI ITILFollowup to a Bileto Message.
     *
     * @param mixed[] $itil_followup
     *
     * @return mixed[]
     */
    public function exportItilFollowupAsMessage(array $itil_followup): array
    {
        $created_at = new \DateTimeImmutable($itil_followup['date']);
        $via = $this->fetchVia($itil_followup['requesttypes_id']);
        $document_items = $this->fetchDocumentItems('ITILFollowup', $itil_followup['id']);
        $message_documents = $this->exportDocumentItemsToMessageDocuments($document_items);
        $content = html_entity_decode($itil_followup['content']);

        return [
            'id' => "followup-{$itil_followup['id']}",
            'createdAt' => $created_at->format(\DateTimeInterface::RFC3339),
            'createdById' => strval($itil_followup['users_id']),
            'isConfidential' => $itil_followup['is_private'] === 1,
            'via' => $via,
            'content' => $content,
            'messageDocuments' => $message_documents,
        ];
    }

    /**
     * Export GLPI DocumentItems to Bileto MessageDocuments
     *
     * @param mixed[] $document_items
     *
     * @return mixed[]
     */
    public function exportDocumentItemsToMessageDocuments(array $document_items): array
    {
        $message_documents = [];

        foreach ($document_items as $document_item) {
            $documents = $this->database->fetchAll(<<<SQL
                SELECT name, filepath
                FROM glpi_documents
                WHERE id = :document_id
            SQL, [
                ':document_id' => $document_item['documents_id'],
            ]);

            if (!$documents) {
                echo "[Warning] Skipping Document Item (id {$document_item['id']}): ";
                echo "the related Document (id {$document_item['documents_id']}) doesn't exist\n";
                continue;
            }

            $message_documents[] = [
                'name' => $documents[0]['name'],
                'filepath' => $documents[0]['filepath'],
            ];
        }

        return $message_documents;
    }

    /**
     * Convert a GLPI weight to a Bileto weight.
     *
     * @return 'low'|'medium'|'high'
     */
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
     * Return the (Bileto) organization id corresponding to the given (GLPI) entity id.
     *
     * It is especially useful when organizations are merged by names.
     */
    private function getOrganizationId(int $entity_id, string $context): string
    {
        if (!isset($this->entities_to_orgas[$entity_id])) {
            echo "[Error] {$context} is invalid: Entity (id {$entity_id}) doesn't exist\n";

            $organization_id = strval($entity_id);
            $this->entities_to_orgas[$entity_id] = $organization_id;
            return $organization_id;
        }

        return $this->entities_to_orgas[$entity_id];
    }

    /**
     * Fetch the actors of a GLPI actors and return the ids of the requester
     * and the assignee if any.
     *
     * @param array<string, mixed> $ticket
     *
     * @return array{?string, ?string, string[]}
     */
    private function fetchTicketActors(array $ticket): array
    {
        $requester_id = null;
        $assignee_id = null;

        $ticket_users = $this->database->fetchAll(<<<SQL
            SELECT type, users_id
            FROM glpi_tickets_users
            WHERE tickets_id = :ticket_id
        SQL, [
            ':ticket_id' => $ticket['id'],
        ]);

        $requester = ArrayHelper::find($ticket_users, function (array $ticket_user): bool {
            return $ticket_user['type'] === 1;
        });
        if ($requester) {
            $requester_id = strval($requester['users_id']);
        }

        $assignee = ArrayHelper::find($ticket_users, function (array $ticket_user): bool {
            return $ticket_user['type'] === 2;
        });
        if ($assignee) {
            $assignee_id = strval($assignee['users_id']);
        }

        $observer_ids = [];
        foreach ($ticket_users as $ticket_user) {
            if ($ticket_user['type'] === 3) {
                $observer_ids[] = strval($ticket_user['users_id']);
            }
        }

        return [$requester_id, $assignee_id, $observer_ids];
    }

    /**
     * Fetch the GLPI DocumentItems of the given item.
     *
     * @param 'Ticket'|'ITILSolution'|'TicketTask'|'ITILFollowup' $item_type
     *
     * @return array<array<string, mixed>>
     */
    private function fetchDocumentItems(string $item_type, int $item_id): array
    {
        return $this->database->fetchAll(<<<SQL
            SELECT id, documents_id
            FROM glpi_documents_items
            WHERE itemtype = :item_type
            AND items_id = :item_id
        SQL, [
            ':item_type' => $item_type,
            ':item_id' => $item_id,
        ]);
    }

    /**
     * Fetch the given GLPI RequestType and convert it to a Bileto "via".
     *
     * @return 'email'|'webapp'
     */
    private function fetchVia(int $request_type_id): string
    {
        $request_type = $this->database->fetchValue(<<<SQL
            SELECT name
            FROM glpi_requesttypes
            WHERE id = :request_type_id
        SQL, [
            ':request_type_id' => $request_type_id,
        ]);

        $request_type = strtolower($request_type);
        if ($request_type === 'email' || $request_type === 'e-mail') {
            return 'email';
        } else {
            return 'webapp';
        }
    }
}
