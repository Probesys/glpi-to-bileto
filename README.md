# GLPI To Bileto

This project aims to export GLPI data into a ZIP file for import into Bileto.

- GLPI: [glpi-project.org](https://glpi-project.org/)
- Bileto: [bileto.fr](https://bileto.fr/)

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
