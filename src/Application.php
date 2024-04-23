<?php

// This file is part of GLPI To Bileto.
// Copyright 2024 Probesys
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App;

class Application
{
    private Database $database;

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
     * Create a Request reading the CLI arguments.
     *
     * @param non-empty-list<string> $arguments
     */
    public function execute(array $arguments): int
    {
        if (count($arguments) !== 1) {
            echo $this->usage();
            return -1;
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

            echo "Getting contracts…\n";
            $glpi_data['contracts'] = $this->exportProjectTasksAsContracts();
            echo "OK\n";

            echo "Getting tickets…\n";
            $tickets = $this->exportTicketsAsTickets();
            foreach ($tickets as $ticket) {
                $glpi_data["tickets/{$ticket['organizationId']}/{$ticket['id']}"] = $ticket;
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

    public function usage(): string
    {
        return <<<TEXT
        Usage: php bin/glpi-export
        TEXT;
    }

    /**
     * @return array<mixed[]>
     */
    public function exportEntitiesAsOrganizations(): array
    {
        $data = $this->database->fetchAll(<<<SQL
            SELECT id, completename
            FROM glpi_entities
        SQL);

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

            if (!$email) {
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

            $user_profiles = $this->database->fetchAll(<<<SQL
                SELECT profiles_id, entities_id
                FROM glpi_profiles_users
                WHERE users_id = :user_id
            SQL, [
                ':user_id' => $user['id'],
            ]);

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

        $data = $this->database->fetchAll(<<<SQL
            SELECT id, projects_id, plan_start_date, plan_end_date, name, planned_duration
            FROM glpi_projecttasks
        SQL);

        $contracts = [];

        foreach ($data as $project_task) {
            $project_id = $project_task['projects_id'];

            if (!isset($projects_by_ids[$project_id])) {
                echo "[Warning] Skipping project task {$project_task['id']}: ";
                echo "project {$project_id} doesn't exist\n";
                continue;
            }

            $project = $projects_by_ids[$project_id];

            if (!isset($projects_to_contracts[$project_id])) {
                echo "[Warning] Skipping project task {$project_task['id']}: ";
                echo "contract for project {$project_id} doesn't exist\n";
                continue;
            }

            $contract_id = $projects_to_contracts[$project_id];

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
     * @return array<mixed[]>
     */
    public function exportTicketsAsTickets(): array
    {
        $data = $this->database->fetchAll(<<<SQL
            SELECT id, date, users_id_recipient, name, content, type, status,
                   urgency, impact, priority, entities_id, requesttypes_id
            FROM glpi_tickets
        SQL);

        $tickets = [];
        foreach ($data as $ticket) {
            $messages = [];
            $messages[] = $this->exportTicketAsMessage($ticket);

            $created_at = new \DateTimeImmutable($ticket['date']);

            list(
                $requester_id,
                $assignee_id,
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
                $messages[] = $this->exportItilSolutionAsMessage($itil_solution);
            }

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

            $contract_id = $contract_ids[0] ?? null;

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

                $messages[] = $this->exportTicketTaskAsMessage($ticket_task);
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
                $messages[] = $this->exportItilFollowupAsMessage($itil_followup);
            }

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
                'organizationId' => strval($ticket['entities_id']),
                'solutionId' => $solution_id,
                'contractIds' => $contract_ids,
                'timeSpents' => $time_spents,
                'messages' => $messages,
            ];
        }

        return $tickets;
    }

    /**
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
        $document_items = $this->fetchDocumentItems('ITILSolution', $itil_solution['id']);
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
     * @param mixed[] $ticket_task
     *
     * @return mixed[]
     */
    public function exportTicketTaskAsMessage(array $ticket_task): array
    {
        $created_at = new \DateTimeImmutable($ticket_task['date']);
        $document_items = $this->fetchDocumentItems('TicketTask', $ticket_task['id']);
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
        $via = $this->fetchVia($itil_followup['requesttypes_id']);
        $document_items = $this->fetchDocumentItems('ITILFollowup', $itil_followup['id']);
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
            $documents = $this->database->fetchAll(<<<SQL
                SELECT name, filepath
                FROM glpi_documents
                WHERE id = :document_id
            SQL, [
                ':document_id' => $document_item['documents_id'],
            ]);

            if (!$documents) {
                echo "[Warning] Document {$document_item['documents_id']} doesn't exist ";
                echo "(referenced by document_item {$document_item['id']})\n";
                continue;
            }

            $message_documents[] = [
                'name' => $documents[0]['name'],
                'filepath' => $documents[0]['filepath'],
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
     * @param array<string, mixed> $ticket
     *
     * @return array{?string, ?string}
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

        return [$requester_id, $assignee_id];
    }

    /**
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
