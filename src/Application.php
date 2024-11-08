<?php

// This file is part of GLPI To Bileto.
// Copyright 2024 Probesys
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App;

class Application
{
    private string $app_path;

    private Database $database;

    /**
     * @var array{
     *     'dry run': bool,
     *     'hostname': ?string,
     *     'ignore contracts': bool,
     *     'merge organizations': bool,
     *     'merge users': bool,
     *     'no warning': bool,
     *     'since': ?\DateTimeImmutable,
     *     'skip on error': bool,
     * } $options
     */
    private array $options;

    private ?string $notification_uuid;

    /** @var array<int, string> */
    private array $entities_to_orgas;

    /** @var array<int, string> */
    private array $glpi_users_to_users;

    /** @var array<int, string> */
    private array $project_tasks_to_contracts;

    /** @var Plugin[] */
    private array $plugins;

    public function __construct(string $app_path)
    {
        $dotenv = new Dotenv("{$app_path}/.env");

        $this->app_path = $app_path;

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
            'dry run' => false,
            'hostname' => null,
            'ignore contracts' => false,
            'merge organizations' => false,
            'merge users' => false,
            'no warning' => false,
            'since' => null,
            'skip on error' => false,
        ];

        $this->notification_uuid = null;
        $this->entities_to_orgas = [];
        $this->glpi_users_to_users = [];
        $this->project_tasks_to_contracts = [];

        foreach ($arguments as $argument) {
            if ($argument === '--help' || $argument === '-h') {
                echo $this->usage();
                return 0;
            } elseif ($argument === '--dry-run') {
                $this->options['dry run'] = true;
            } elseif (str_starts_with($argument, '--hostname=')) {
                $this->options['hostname'] = substr($argument, strlen('--hostname='));
            } elseif ($argument === '--skip-on-error') {
                $this->options['skip on error'] = true;
            } elseif ($argument === '--merge-organizations') {
                $this->options['merge organizations'] = true;
            } elseif ($argument === '--merge-users') {
                $this->options['merge users'] = true;
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
            } elseif ($argument === '--no-warning') {
                $this->options['no warning'] = true;
            } else {
                echo "Unrecognized option: {$argument}\n\n";
                echo $this->usage();
                return -1;
            }
        }

        $this->plugins = $this->loadPlugins();

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

            echo "Getting teams…\n";
            $glpi_data['teams'] = $this->exportTeams();
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
            $this->critical($e->getMessage());
            return -2;
        }

        if ($this->options['dry run']) {
            echo 'GLPI data exported (dry run)';
            return 0;
        }

        echo "Generating the archive…\n";
        $files = [];
        foreach ($glpi_data as $name => $data) {
            try {
                $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                $files["{$name}.json"] = $json;
            } catch (\JsonException $error) {
                $this->critical(
                    "Cannot export the {$name}.json file:",
                    "encoding the data to JSON failed ({$error->getMessage()}).",
                );
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
          --dry-run                  simulate an export, but do not write the archive
          --help -h                  display this help message
          --hostname                 set the GLPI hostname (required to link emails with tickets)
          --ignore-contracts         do not load the contracts from ProjectBridge
          --merge-organizations      merge the organizations having the same name
          --merge-users              merge the users having the same email
          --no-warning               do not display the warnings
          --since=[YYYY-MM-DD]       export tickets and contracts after the given date
          --skip-on-error            skip data concerned by an error
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

        $count = count($data);
        echo "{$count} entities found\n";

        $organizations = [];
        $names_to_ids = [];

        foreach ($data as $entity) {
            // Keep the entry id in memory as the plugins may change the value.
            $entity_id = intval($entity['id']);

            $entity = $this->callPluginsPreProcess($entity, 'entity');

            if ($entity === null) {
                continue;
            }

            $organization_id = strval($entity['id']);
            $name = $entity['name'];

            if ($this->options['merge organizations']) {
                if (isset($names_to_ids[$name])) {
                    $organization_id = $names_to_ids[$name];
                } else {
                    $names_to_ids[$name] = $organization_id;
                }
            }

            if (!isset($organizations[$organization_id])) {
                $organizations[$organization_id] = [
                    'id' => $organization_id,
                    'name' => $name,
                ];
            }

            $this->entities_to_orgas[$entity_id] = $organization_id;
        }

        $organizations = array_values($organizations);
        $organizations = $this->callPluginsPostProcess($organizations, 'organizations');

        $count = count($organizations);
        echo "{$count} organizations exported\n";

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

        $count = count($data);
        echo "{$count} profiles found\n";

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

        $roles = $this->callPluginsPostProcess($roles, 'roles');

        $count = count($roles);
        echo "{$count} roles exported\n";

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

        // When a user is deleted, references are replaced by id 0. But there
        // is no user with id 0, so we add a fake one in order to enable the
        // plugins to hook on it. By default, as this user doesn't have an
        // email, it will not be exported, but a plugin can add one.
        $data[] = [
            'id' => 0,
            'name' => 'ghost',
            'realname' => '',
            'firstname' => '',
            'entities_id' => 0,
            'user_dn' => '',
        ];

        $count = count($data);
        echo "{$count} users found\n";

        $users = [];
        $emails_to_ids = [];

        foreach ($data as $user) {
            $glpi_user_id = intval($user['id']);

            $email = $this->database->fetchValue(<<<SQL
                SELECT email
                FROM glpi_useremails
                WHERE users_id = :user_id
                AND is_default = true
            SQL, [
                ':user_id' => $glpi_user_id,
            ]);

            $user['email'] = strtolower($email);

            $user = $this->callPluginsPreProcess($user, 'user');

            if ($user === null) {
                continue;
            }

            $user_id = strval($user['id']);
            $email = $user['email'];

            $name = '';
            if ($user['realname'] || $user['firstname']) {
                $realname = $user['realname'] ?? '';
                $firstname = $user['firstname'] ?? '';
                $name = trim("{$firstname} {$realname}");
            } else {
                $name = $user['name'];
            }

            if (!$email && $this->options['skip on error']) {
                $this->warning("Skipping User {$name} (id {$glpi_user_id}): email is missing.");
                continue;
            } elseif (!$email) {
                $this->error("User {$name} (id {$glpi_user_id}) is invalid: email is missing.");
                $email = '';
            }

            if ($this->options['merge users'] && $email) {
                if (isset($emails_to_ids[$email])) {
                    $user_id = $emails_to_ids[$email];
                } else {
                    $emails_to_ids[$email] = $user_id;
                }
            }

            if (!isset($users[$user_id])) {
                $ldap_identifier = null;
                if ($user['user_dn']) {
                    $ldap_identifier = $user['name'];
                }

                $user_profiles = $this->database->fetchAll(<<<SQL
                    SELECT id, profiles_id, entities_id, is_recursive
                    FROM glpi_profiles_users
                    WHERE users_id = :user_id
                SQL, [
                    ':user_id' => $glpi_user_id,
                ]);

                $authorizations = [];
                foreach ($user_profiles as $user_profile) {
                    $context = "User Profile (id {$user_profile['id']}) of User {$name} (id {$glpi_user_id})";

                    if ($user_profile['is_recursive']) {
                        $this->warning(
                            "{$context}: recursive profiles are not supported by Bileto,",
                            'exporting as non-recursive.'
                        );
                    }

                    $organization_id = $this->getOrganizationId($user_profile['entities_id']);

                    if ($organization_id === null) {
                        $this->skipOrInvalid($context, "Entity (id {$user_profile['entities_id']}) doesn't exist.");

                        if ($this->options['skip on error']) {
                            continue;
                        }
                    }

                    $authorizations[] = [
                        'roleId' => strval($user_profile['profiles_id']),
                        'organizationId' => $organization_id,
                    ];
                }

                $context = "User {$name} (id {$glpi_user_id})";
                $organization_id = $this->getOrganizationId($user['entities_id']);

                if ($organization_id === null) {
                    $this->warning("{$context}: Entity (id {$user['entities_id']}) doesn't exist, using null.");
                }

                $users[$user_id] = [
                    'id' => $user_id,
                    'email' => $email,
                    'name' => $name,
                    'ldapIdentifier' => $ldap_identifier,
                    'organizationId' => $organization_id,
                    'authorizations' => $authorizations,
                ];
            }

            $this->glpi_users_to_users[$glpi_user_id] = $user_id;
        }

        $users = array_values($users);
        $users = $this->callPluginsPostProcess($users, 'users');

        $count = count($users);
        echo "{$count} users exported\n";

        return $users;
    }

    /**
     * Export Teams.
     *
     * This method is not exporting anything from GLPI for now, but it allows
     * to create plugins to create teams.
     *
     * @return array<mixed[]>
     */
    public function exportTeams(): array
    {
        $count = 0;
        echo "{$count} teams found\n";

        $teams = $this->callPluginsPostProcess([], 'teams');

        $count = count($teams);
        echo "{$count} teams exported\n";

        return $teams;
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
            SELECT pb.project_id, pb.contract_id
            FROM glpi_plugin_projectbridge_contracts pb,
                 glpi_contracts c,
                 glpi_projects p
            WHERE pb.project_id = p.id
            AND pb.contract_id = c.id
        SQL);

        $sql = <<<SQL
            SELECT id, projects_id, plan_start_date, plan_end_date, name, planned_duration
            FROM glpi_projecttasks
        SQL;
        $parameters = [];

        $since = $this->options['since'];
        if ($since) {
            $sql .= ' WHERE plan_end_date >= :since OR plan_end_date IS NULL';
            $parameters[':since'] = $since->format('Y-m-d');
        }

        $statement = $this->database->prepare($sql);
        $statement->execute($parameters);
        $data = $statement->fetchAll();

        $count = count($data);
        echo "{$count} project tasks found\n";

        $contracts = [];

        foreach ($data as $project_task) {
            $project_id = $project_task['projects_id'];

            if (!isset($projects_by_ids[$project_id])) {
                if ($this->options['skip on error']) {
                    $this->warning(
                        "Skipping Project Task (id {$project_task['id']}):",
                        "its related Project (id {$project_id}) doesn't exist.",
                    );
                } else {
                    $this->error(
                        "Skipping Project Task (id {$project_task['id']}):",
                        "its related Project (id {$project_id}) doesn't exist.",
                    );
                }
                continue;
            }

            if (!isset($projects_to_contracts[$project_id])) {
                if ($this->options['skip on error']) {
                    $this->warning(
                        "Skipping Project Task (id {$project_task['id']}):",
                        "its related Project (id {$project_id}) is not attached to a Contract.",
                    );
                } else {
                    $this->error(
                        "Skipping Project Task (id {$project_task['id']}):",
                        "its related Project (id {$project_id}) is not attached to a Contract.",
                    );
                }
                continue;
            }

            $project = $projects_by_ids[$project_id];
            $contract_id = $projects_to_contracts[$project_id];

            if (!isset($contracts_by_ids[$contract_id])) {
                if ($this->options['skip on error']) {
                    $this->warning(
                        "Skipping Project Task (id {$project_task['id']}):",
                        "its related Project (id {$project_id})",
                        "is attached to an unknown Contract (id {$contract_id}).",
                    );
                } else {
                    $this->error(
                        "Skipping Project Task (id {$project_task['id']}):",
                        "its related Project (id {$project_id})",
                        "is attached to an unknown Contract (id {$contract_id}).",
                    );
                }
                continue;
            }

            if (!isset($project_task['plan_start_date'])) {
                if ($this->options['skip on error']) {
                    $this->warning(
                        "Skipping Project Task (id {$project_task['id']}):",
                        "the plan_start_date field is not set.",
                    );
                    continue;
                } else {
                    $this->error(
                        "Project Task (id {$project_task['id']}) is invalid:",
                        "the plan_start_date field is not set.",
                    );
                }
            }

            if (!isset($project_task['plan_end_date'])) {
                if ($this->options['skip on error']) {
                    $this->warning(
                        "Skipping Project Task (id {$project_task['id']}):",
                        "the plan_end_date field is not set.",
                    );
                    continue;
                } else {
                    $this->error(
                        "Project Task (id {$project_task['id']}) is invalid:",
                        "the plan_end_date field is not set.",
                    );
                }
            }

            $contract = $contracts_by_ids[$contract_id];

            $start_at = null;
            if (isset($project_task['plan_start_date'])) {
                $start_at = new \DateTimeImmutable($project_task['plan_start_date']);
            }

            $end_at = null;
            if (isset($project_task['plan_end_date'])) {
                $end_at = new \DateTimeImmutable($project_task['plan_end_date']);
            }

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
            $organization_id = $this->getOrganizationId($contract['entities_id']);

            if ($organization_id === null) {
                $this->skipOrInvalid($context, "Entity (id {$contract['entities_id']}) doesn't exist.");

                if ($this->options['skip on error']) {
                    continue;
                }
            }

            $project_task_id = intval($project_task['id']);
            $contract_id = strval($project_task_id);

            $max_hours = intval($project_task['planned_duration'] / 60 / 60);

            if ($max_hours <= 0) {
                $this->skipOrInvalid($context, 'Max hours is equal to 0.');

                if ($this->options['skip on error']) {
                    continue;
                }
            }

            $contract = [
                'id' => $contract_id,
                'name' => $name,
                'startAt' => $start_at?->format(\DateTimeInterface::RFC3339),
                'endAt' => $end_at?->format(\DateTimeInterface::RFC3339),
                'maxHours' => $max_hours,
                'notes' => $contract['comment'],
                'organizationId' => $organization_id,
                'timeAccountingUnit' => 30,
                'hoursAlert' => $hours_alert,
                'dateAlert' => $date_alert,
            ];

            $contract = $this->callPluginsPreProcess($contract, 'contract');

            if ($contract === null) {
                continue;
            }

            $contracts[] = $contract;

            $this->project_tasks_to_contracts[$project_task_id] = $contract['id'];
        }

        $contracts = $this->callPluginsPostProcess($contracts, 'contracts');

        $count = count($contracts);
        echo "{$count} contracts exported\n";

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

        $count = count($data);
        echo "{$count} categories found\n";

        $labels = [];

        foreach ($data as $category) {
            $labels[] = [
                'id' => strval($category['id']),
                'name' => $category['completename'],
                'description' => $category['comment'],
                'color' => 'grey',
            ];
        }

        $labels = $this->callPluginsPostProcess($labels, 'labels');

        $count = count($labels);
        echo "{$count} labels exported\n";

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
            SELECT id, date, date_creation, users_id_recipient, name, content, type, status,
                urgency, impact, priority, entities_id, requesttypes_id,
                itilcategories_id
            FROM glpi_tickets
        SQL;
        $parameters = [];

        $since = $this->options['since'];
        if ($since) {
            $sql .= ' WHERE date_creation >= ? OR date >= ?';
            $parameters[] = $since->format('Y-m-d');
            $parameters[] = $since->format('Y-m-d');
        }

        $statement = $this->database->prepare($sql);
        $statement->execute($parameters);
        $data = $statement->fetchAll();

        $count = count($data);
        echo "{$count} tickets found\n";

        $tickets = [];
        foreach ($data as $ticket) {
            $context = "Ticket (id {$ticket['id']})";

            $ticket = $this->callPluginsPreProcess($ticket, 'ticket');

            if ($ticket === null) {
                continue;
            }

            $title = html_entity_decode($ticket['name']);

            list(
                $requester_id,
                $assignee_id,
                $observer_ids,
            ) = $this->fetchTicketActors($ticket, $context);

            $messages = [];

            $message = $this->exportTicketAsMessage($ticket);
            $created_by_id = $message['createdById'];

            if ($message['content'] === '') {
                $message['content'] = $title;
            }

            if ($created_by_id === null && $requester_id === null) {
                $this->skipOrInvalid($context, 'author and requester Users do not exist.');
                if ($this->options['skip on error']) {
                    continue;
                }
            } elseif ($created_by_id === null) {
                $this->warning("{$context}: author is not set, using requester by default.");
                $created_by_id = $requester_id;
                $message['createdById'] = $created_by_id;
            } elseif ($requester_id === null) {
                $this->warning("{$context}: requester is not set, using author by default.");
                $requester_id = $created_by_id;
            }

            $messages[] = $message;

            $date_creation = $ticket['date_creation'] ?? $ticket['date'];
            $created_at = new \DateTimeImmutable($date_creation);
            $updated_at = $created_at;

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
                SELECT id, status, date_creation, users_id, content, items_id AS tickets_id
                FROM glpi_itilsolutions
                WHERE items_id = :ticket_id
                AND itemtype = 'Ticket'
            SQL, [
                ':ticket_id' => $ticket['id'],
            ]);

            $solution_id = null;

            foreach ($itil_solutions as $itil_solution) {
                $solution_context = "Solution Message (id {$itil_solution['id']}) of {$context}";

                $message = $this->exportItilSolutionAsMessage($itil_solution);

                if ($message['content'] === '') {
                    $this->warning("{$solution_context}: the message content is empty, using a default value.");
                    $message['content'] = '<p>Ticket résolu.<p>';
                }

                if ($message['createdById'] === null) {
                    $this->skipOrInvalid($solution_context, "User (id {$itil_solution['users_id']}) doesn't exist.");

                    if ($this->options['skip on error']) {
                        continue;
                    }
                }

                $messages[] = $message;

                if ($itil_solution['status'] === 2 || $itil_solution['status'] === 3) {
                    $solution_id = $message['id'];
                }
            }

            if ($this->options['ignore contracts']) {
                $contract_ids = [];
            } else {
                $project_tasks_ids = $this->database->fetchValues(<<<SQL
                    SELECT projecttasks_id
                    FROM glpi_projecttasks_tickets
                    WHERE tickets_id = :ticket_id
                SQL, [
                    ':ticket_id' => $ticket['id'],
                ]);

                $contract_ids = [];

                foreach ($project_tasks_ids as $project_task_id) {
                    $contract_id = $this->getContractId($project_task_id);

                    if ($contract_id === null) {
                        $this->skipOrInvalid($context, "Project Task (id {$project_task_id}) doesn't exist.");

                        if ($this->options['skip on error']) {
                            continue;
                        }
                    }

                    $contract_ids[] = $contract_id;
                }
            }

            $contract_id = $contract_ids[0] ?? null;

            $label_ids = [];
            if ($ticket['itilcategories_id'] > 0) {
                $label_ids[] = strval($ticket['itilcategories_id']);
            }

            $ticket_tasks = $this->database->fetchAll(<<<SQL
                SELECT id, date, date_creation, actiontime, users_id, is_private, content, tickets_id
                FROM glpi_tickettasks
                WHERE tickets_id = :ticket_id
            SQL, [
                ':ticket_id' => $ticket['id'],
            ]);

            $time_spents = [];
            foreach ($ticket_tasks as $ticket_task) {
                $task_context = "Ticket Task (id {$ticket_task['id']}) of {$context}";

                $message = $this->exportTicketTaskAsMessage($ticket_task);

                if ($message['content'] !== '') {
                    if ($message['createdById'] === null) {
                        $this->skipOrInvalid($task_context, "User (id {$ticket_task['users_id']}) doesn't exist.");

                        if ($this->options['skip on error']) {
                            continue;
                        }
                    }

                    $messages[] = $message;
                }

                $time_spent = $this->exportTicketTaskAsTimeSpent($ticket_task);

                if ($time_spent['time'] > 0) {
                    if ($time_spent['createdById'] === null) {
                        $this->skipOrInvalid($task_context, "User (id {$ticket_task['users_id']}) doesn't exist.");

                        if ($this->options['skip on error']) {
                            continue;
                        }
                    }

                    $time_spent['contractId'] = $contract_id;
                    $time_spents[] = $time_spent;
                }
            }

            $itil_followups = $this->database->fetchAll(<<<SQL
                SELECT id, date, date_creation, users_id, is_private, content, requesttypes_id, items_id AS tickets_id
                FROM glpi_itilfollowups
                WHERE itemtype = 'Ticket'
                AND items_id = :ticket_id
            SQL, [
                ':ticket_id' => $ticket['id'],
            ]);

            foreach ($itil_followups as $itil_followup) {
                $followup_context = "Followup Message (id {$itil_followup['id']}) of {$context}";
                $message = $this->exportItilFollowupAsMessage($itil_followup);

                if ($message['content'] === '') {
                    $this->skipOrInvalid($followup_context, 'The message content is empty.');

                    if ($this->options['skip on error']) {
                        continue;
                    }
                }

                if ($message['createdById'] === null) {
                    $this->skipOrInvalid($followup_context, "User (id {$itil_followup['users_id']}) doesn't exist.");

                    if ($this->options['skip on error']) {
                        continue;
                    }
                }

                $messages[] = $message;
            }

            foreach ($messages as $message) {
                $message_created_at = $message['createdAt'];
                $message_created_at = new \DateTimeImmutable($message_created_at);

                if ($message_created_at > $updated_at) {
                    $updated_at = $message_created_at;
                }
            }

            $organization_id = $this->getOrganizationId($ticket['entities_id']);

            if ($organization_id === null) {
                $this->skipOrInvalid($context, "Entity (id {$ticket['entities_id']}) doesn't exist.");

                if ($this->options['skip on error']) {
                    continue;
                }
            }

            $tickets[] = [
                'id' => strval($ticket['id']),
                'createdAt' => $created_at->format(\DateTimeInterface::RFC3339),
                'updatedAt' => $updated_at->format(\DateTimeInterface::RFC3339),
                'createdById' => $created_by_id,
                'type' => $type,
                'status' => $status,
                'title' => $title,
                'urgency' => $this->getWeight($ticket['urgency']),
                'impact' => $this->getWeight($ticket['impact']),
                'priority' => $this->getWeight($ticket['priority']),
                'requesterId' => $requester_id,
                'assigneeId' => $assignee_id,
                'observerIds' => $observer_ids,
                'organizationId' => $organization_id,
                'solutionId' => $solution_id,
                'contractIds' => $contract_ids,
                'labelIds' => $label_ids,
                'timeSpents' => $time_spents,
                'messages' => $messages,
            ];
        }

        $tickets = $this->callPluginsPostProcess($tickets, 'tickets');

        $count = count($tickets);
        echo "{$count} tickets exported\n";

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
        $date_creation = $ticket['date_creation'] ?? $ticket['date'];
        $created_at = new \DateTimeImmutable($date_creation);
        $via = $this->fetchVia($ticket['requesttypes_id']);
        $document_items = $this->fetchDocumentItems('Ticket', $ticket['id']);
        $message_documents = $this->exportDocumentItemsToMessageDocuments($document_items);
        $content = $this->sanitizeContent($ticket['content'] ?? '');

        return [
            'id' => "ticket-{$ticket['id']}",
            'createdAt' => $created_at->format(\DateTimeInterface::RFC3339),
            'createdById' => $this->getUserId($ticket['users_id_recipient']),
            'isConfidential' => false,
            'via' => $via,
            'emailId' => $this->getEmailId($ticket['id']),
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
        $content = $this->sanitizeContent($itil_solution['content'] ?? '');

        return [
            'id' => "solution-{$itil_solution['id']}",
            'createdAt' => $created_at->format(\DateTimeInterface::RFC3339),
            'createdById' => $this->getUserId($itil_solution['users_id']),
            'isConfidential' => false,
            'via' => 'webapp',
            'emailId' => $this->getEmailId($itil_solution['tickets_id']),
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
        $date_creation = $ticket_task['date_creation'] ?? $ticket_task['date'];
        $created_at = new \DateTimeImmutable($date_creation);
        $time = intval($ticket_task['actiontime'] / 60);

        return [
            'createdAt' => $created_at->format(\DateTimeInterface::RFC3339),
            'createdById' => $this->getUserId($ticket_task['users_id']),
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
        $date_creation = $ticket_task['date_creation'] ?? $ticket_task['date'];
        $created_at = new \DateTimeImmutable($date_creation);
        $document_items = $this->fetchDocumentItems('TicketTask', $ticket_task['id']);
        $message_documents = $this->exportDocumentItemsToMessageDocuments($document_items);
        $content = $this->sanitizeContent($ticket_task['content'] ?? '');

        return [
            'id' => "ticket-task-{$ticket_task['id']}",
            'createdAt' => $created_at->format(\DateTimeInterface::RFC3339),
            'createdById' => $this->getUserId($ticket_task['users_id']),
            'isConfidential' => $ticket_task['is_private'] === 1,
            'via' => 'webapp',
            'emailId' => $this->getEmailId($ticket_task['tickets_id']),
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
        $date_creation = $itil_followup['date_creation'] ?? $itil_followup['date'];
        $created_at = new \DateTimeImmutable($date_creation);
        $via = $this->fetchVia($itil_followup['requesttypes_id']);
        $document_items = $this->fetchDocumentItems('ITILFollowup', $itil_followup['id']);
        $message_documents = $this->exportDocumentItemsToMessageDocuments($document_items);
        $content = $this->sanitizeContent($itil_followup['content'] ?? '');

        return [
            'id' => "followup-{$itil_followup['id']}",
            'createdAt' => $created_at->format(\DateTimeInterface::RFC3339),
            'createdById' => $this->getUserId($itil_followup['users_id']),
            'isConfidential' => $itil_followup['is_private'] === 1,
            'via' => $via,
            'emailId' => $this->getEmailId($itil_followup['tickets_id']),
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
                $this->warning(
                    "Skipping Document Item (id {$document_item['id']}):",
                    "the related Document (id {$document_item['documents_id']}) doesn't exist.",
                );
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
    private function getOrganizationId(int $entity_id): ?string
    {
        return $this->entities_to_orgas[$entity_id] ?? null;
    }

    /**
     * Return the (Bileto) user id corresponding to the given (GLPI) user id.
     *
     * It is especially useful when users are merged by emails.
     */
    private function getUserId(int $glpi_user_id): ?string
    {
        return $this->glpi_users_to_users[$glpi_user_id] ?? null;
    }

    /**
     * Return the (Bileto) contract id corresponding to the given (GLPI) project task id.
     */
    private function getContractId(int $project_task_id): ?string
    {
        return $this->project_tasks_to_contracts[$project_task_id] ?? null;
    }

    /**
     * Fetch the actors of a GLPI actors and return the ids of the requester,
     * the assignee and the observers if any.
     *
     * If the ticket has multiple requesters or assignees, only the first one
     * is picked. The others are returned as observers.
     *
     * @param array<string, mixed> $ticket
     *
     * @return array{?string, ?string, string[]}
     */
    private function fetchTicketActors(array $ticket, string $context): array
    {
        $requester_id = null;
        $assignee_id = null;
        $observer_ids = [];

        $ticket_users = $this->database->fetchAll(<<<SQL
            SELECT type, users_id
            FROM glpi_tickets_users
            WHERE tickets_id = :ticket_id
        SQL, [
            ':ticket_id' => $ticket['id'],
        ]);

        $actor_context = "Actor of {$context}";

        foreach ($ticket_users as $ticket_user) {
            $user_id = $this->getUserId($ticket_user['users_id']);

            if ($user_id === null) {
                $this->skipOrInvalid($actor_context, "User {$ticket_user['users_id']} doesn't exist.");

                if ($this->options['skip on error']) {
                    continue;
                }
            }

            if ($ticket_user['type'] === 1 && $requester_id === null) {
                $requester_id = $user_id;
            } elseif ($ticket_user['type'] === 2 && $assignee_id === null) {
                $assignee_id = $user_id;
            } else {
                $observer_ids[] = $user_id;
            }
        }

        $observer_ids = array_filter($observer_ids, function ($observer_id) {
            return $observer_id !== null;
        });

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

    private function getEmailId(int $ticket_id): ?string
    {
        if ($this->options['hostname'] === null) {
            return null;
        }

        $hostname = $this->options['hostname'];

        if (!$this->notification_uuid) {
            $this->notification_uuid = $this->database->fetchValue(<<<SQL
                SELECT value
                FROM glpi_configs
                WHERE name = 'notification_uuid'
            SQL);
        }

        return "GLPI_{$this->notification_uuid}-Ticket-{$ticket_id}@{$hostname}";
    }

    /**
     * Return content as decoded HTML.
     *
     * If the content is detected as plain text, the newlines are replaced by `<br>`.
     */
    private function sanitizeContent(string $content): string
    {
        // Look for any "</[tag]>" or "<br />" patterns
        $is_html = (
            preg_match('/&#60;\/\w+&#62;/', $content) === 1 ||
            preg_match('/&lt;\/\w+&gt;/', $content) === 1 ||
            preg_match('/&#60;br\s*(\/)?#62;/', $content) === 1 ||
            preg_match('/&lt;br\s*(\/)?&gt;/', $content) === 1
        );

        if ($is_html) {
            $content = html_entity_decode($content);
        } else {
            $content = nl2br($content);
        }

        return $content;
    }

    /**
     * Load the list of plugins under the plugins/ folder.
     *
     * @return Plugin[]
     */
    private function loadPlugins(): array
    {
        $plugins = [];

        $plugins_folder = $this->app_path . '/plugins';

        $folders = scandir($plugins_folder);
        if ($folders === false) {
            return [];
        }

        foreach ($folders as $folder) {
            $folder_path = "{$plugins_folder}/{$folder}";
            if ($folder === '.' || $folder === '..' || !is_dir($folder_path)) {
                continue;
            }

            $plugin_class = "\\Plugin\\{$folder}\\Plugin";
            $plugin = new $plugin_class($this->database);

            if ($plugin instanceof Plugin) {
                $plugins[] = $plugin;
            }
        }

        return $plugins;
    }

    /**
     * Call the given plugins "preProcess*" hook.
     *
     * @param mixed[] $data
     * @param 'entity'|'user'|'contract'|'ticket' $dataType
     * @return mixed[]|null
     */
    private function callPluginsPreProcess(array $data, string $dataType): ?array
    {
        $hook = 'preProcess' . ucfirst($dataType);

        foreach ($this->plugins as $plugin) {
            $data = $plugin->$hook($data);

            if ($data === null) {
                return null;
            }
        }

        return $data;
    }

    /**
     * Call the given plugins "postProcess*" hook.
     *
     * @param array<mixed[]> $data
     * @param 'organizations'|'roles'|'users'|'teams'|'contracts'|'labels'|'tickets' $dataType
     * @return array<mixed[]>
     */
    private function callPluginsPostProcess(array $data, string $dataType): array
    {
        $hook = 'postProcess' . ucfirst($dataType);

        foreach ($this->plugins as $plugin) {
            $data = $plugin->$hook($data);
        }

        return $data;
    }

    private function critical(string ...$message_parts): void
    {
        $message = implode(' ', $message_parts);

        echo "[Critical] {$message}\n";
    }

    private function error(string ...$message_parts): void
    {
        $message = implode(' ', $message_parts);

        echo "[Error] {$message}\n";
    }

    private function warning(string ...$message_parts): void
    {
        if ($this->options['no warning']) {
            return;
        }

        $message = implode(' ', $message_parts);

        echo "[Warning] {$message}\n";
    }

    private function skipOrInvalid(string $context, string $error): void
    {
        if ($this->options['skip on error']) {
            $this->warning("Skipping {$context}: {$error}");
        } else {
            $this->error("{$context} invalid: {$error}");
        }
    }
}
