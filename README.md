# GLPI To Bileto

This project aims to export GLPI data into a ZIP file for import into Bileto.

- GLPI: [glpi-project.org](https://glpi-project.org/)
- Bileto: [bileto.fr](https://bileto.fr/)

## How to use

### With a running database

Create a file named `.env` and set credentials to the GLPI database (adapt to your case):

```env
DB_HOST = localhost
DB_PORT = 3306
DB_NAME = glpi
DB_USERNAME = mariadb
DB_PASSWORD = mariadb
```

Then, run the command:

```console
$ ./bin/glpi-export
```

This command creates a ZIP archive containing various files with data to be imported into Bileto.

### With a SQL dump

Start the Docker environment:

```console
$ make docker-start
```

Import the SQL file:

```console
$ make docker-db-import FILE=glpi-data.sql
```

Create a file named `.env` and set credentials to the Docker database:

```env
DB_HOST = database
DB_PORT = 3306
DB_NAME = glpi
DB_USERNAME = root
DB_PASSWORD = mariadb
```

Then, run the command:

```console
$ ./bin/glpi-export
```

## Development

Build the Docker image:

```console
$ make docker-build
```

Install the dependencies:

```console
$ make install
```

Run the linters:

```console
$ make lint
```

You can execute the PHP and Composer commands with Docker:

```console
$ ./docker/bin/php --version
$ ./docker/bin/composer --version
```

## Mapping GLPI data to Bileto entities

This is a challenging part, as Bileto is not compatible with GLPI in many ways.
Importing data mean that we lose some.
This section explains how we mapped data from GLPI to Bileto, and attempts to clarify certain incompatibilities.

Naive mapping (GLPI → Bileto):

- Entity → Organization
- Profile → Role
- User + UserEmail → User
  - Profile\_User → Authorization
- ProjectTask + PluginProjectbridgeContract + PluginProjectbridgeContractQuotaAlert + Project + Contract → Contract
- ITILCategory → Label
- Ticket + Ticket\_User + ITILSolution → Ticket
    - Ticket/ITILFollowup/TicketTask/ITILSolution + RequestType → Message
      - Document\_Item + Document → MessageDocument
    - TicketTask → TimeSpent
    - ProjectTask → Contract

Incompatibilities (this is the fun part!):

- GLPI is very big and Bileto a lot smaller; obviously, we lose a lot of data. The goal is to not lose important data.
- GLPI allows sub-entities, while sub-organizations don't exist anymore in Bileto. Sub-entities are "root" organizations then.
- We merge the organizations which have the same names (because they represent the same customer).
- Permissions are very different in GLPI and Bileto, even if they are handled by similar objects (Profile / Role). Also, Bileto defines role types (admin / agent / user) which don't exist in GLPI profiles. This requires manual editing of the `roles.json` file to add a description (if missing), a type and Bileto permissions.
- On the same topic, recursive authorizations are not supported in Bileto, so we export recursive profiles as non-recursive authorizations.
- Users can have several emails in GLPI, but only one in Bileto. We pick the default email from GLPI.
- GLPI has several fields corresponding to the name of the users. If `realname` and/or `firstname` is set, we use these values. Otherwise, we pick the `name` value. The `nickname` is ignored.
- LDAP identifiers are handled quite differently. If GLPI `user_dn` value is set, we consider that the LDAP identifier that can be used in Bileto is the `name` value.
- Bileto only provides English and French locales. We won't export this value and we set `fr_FR` for all the users.
- In GLPI, our contracts are a combination of ProjectTask, PluginProjectbridgeContract, PluginProjectbridgeContractQuotaAlert, Project and Contract. As surprising as it is, it's not too challenging to transform all of these into a single Contract entity in Bileto. It requires some attention still:
  - names are a combination of project name + project task name
  - the quota alert / max hours alert is not always set in the database, we want to default to the value 80
  - the date alert is expressed in months in GLPI, but must be converted to days in Bileto (e.g. `$alert * 30`)
  - the contracts' duration are given in seconds in GLPI, but must be converted to hours in Bileto (e.g. `intval($duration / 60 / 60)`)
  - the notion of "time accounting unit" doesn't exist in GLPI, we set the value to 30 by default
- We can define several ticket requesters and assignees in GLPI, but only one in Bileto. We take the first one that we find in both fields. The other requesters and assignees are exported as observers.
- GLPI tickets have a few fields that are configurable, while they are not in Bileto. Hopefully at Probesys, the type (incident/request) and the status (new/in progress/etc.) are the same in both tools, so we just need to map fields ids from GLPI to their string values in Bileto. However, urgency, impact and priority are different: we export "high" and "very high" values to "high"; "low" and "very low" to "low"; and "medium" to "medium".
- GLPI tickets may have several (ITIL)Solutions, while Bileto only defines a reference to the "current" solution. We consider only the first pending or approved solution from GLPI.
- There are different kind of messages in GLPI: followup, tasks and solutions. The ticket also holds content. It means that each of these items must be exported as Messages in Bileto. A consequence is that we cannot use the ids directly (i.e. a followup and a task may have the same id!). In this case, we prepend the ids by the type of the initial object.
- Spent times are handled through TicketTasks in GLPI. Unfortunately, there are no differences between accounted time and worked time (i.e. the concept of "time accounting unit"). We just export the `actiontime` for both values in Bileto. Also this value is converted from seconds to minutes.
- The source of the messages (i.e. `via`) is configurable in GLPI, but it's not in Bileto (only `webapp` and `email` are actually available). We export the GLPI Ticket RequestType and test the value (i.e. if `request_type.name = email`, the source is "email", and "webapp" otherwise).

## Customize the exportation with plugins

You can create a plugin that will be able to connect to different parts of the script in order to alter the final exportation.
For this, create a folder under the `plugins/` folder:

```console
$ mkdir plugins/MyPlugin
```

Then, in this folder, create a `Plugin.php` file.
The class in it must extends the [`Plugin` class](/src/Plugin.php) and must be in a namespace named after the folder name:

```php
<?php

namespace Plugin\MyPlugin;

class Plugin extends \App\Plugin
{
}
```

There are various hooks available, take a look at the `Plugin` class.

For instance, you can delete the "Root entity" (with the entity id 0) as it has no meaning in Bileto.

```php
<?php

namespace Plugin\MyPlugin;

class Plugin extends \App\Plugin
{
    public function preProcessEntity(array $entity): ?array
    {
        if ($entity['id'] === 0) {
            // Return null to remove an entity
            return null;
        } else {
            return $entity;
        }
    }
}
```

You can also add a final message to all your tickets in order to give the link to your archived GLPI:

```php
<?php

namespace Plugin\MyPlugin;

class Plugin extends \App\Plugin
{
    public function postProcessTickets(array $tickets): array
    {
        return array_map(function ($ticket) {
            $now = new \DateTimeImmutable();
            $tech_user_id = '1'; // This should be the id of the agent who will perform the migration
            $glpi_host = 'https://glpi.example.com';
            $url = "https://{$glpi_host}/front/ticket.form.php?id={$ticket['id']}";

            $message_migration = [
                'id' => "migration-{$ticket['id']}",
                'createdAt' => $now->format(\DateTimeInterface::RFC3339),
                'createdById' => $tech_user_id,
                'isConfidential' => true,
                'content' => "<p>Ticket migrated from GLPI&nbsp;: <a href=\"{$url}\">go to ticket #{$ticket['id']}</a>.</p>",
            ];

            $ticket['messages'][] = $message_migration;

            return $ticket;
        }, $tickets);
    }
}
```

These are just a few examples, but the plugins are powerful enough to adapt the export to your own needs.
