# GLPI To Bileto

This project aims to export GLPI data into a ZIP file for import into Bileto.

- GLPI: [glpi-project.org](https://glpi-project.org/)
- Bileto: [bileto.fr](https://bileto.fr/)

## How to use

Create a file named `.env` and set credentials to the GLPI database:

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
