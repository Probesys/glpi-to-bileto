<?php

// This file is part of GLPI To Bileto.
// Copyright 2024 Probesys
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App;

class Plugin
{
    public function __construct(
        protected Database $database,
    ) {
    }

    /**
     * Allow to modify an entity before it is processed.
     *
     * This can be used to merge organizations together for instance, by
     * changing the $entity['id'].
     *
     * It also can be used to remove an entity by returning null.
     *
     * @param mixed[] $entity
     * @return mixed[]|null
     */
    public function preProcessEntity(array $entity): ?array
    {
        return $entity;
    }

    /**
     * Allow to modify a user before it is processed.
     *
     * This can be used to merge users together for instance, by
     * changing the $user['id'], or to set an email if it's missing.
     *
     * It also can be used to remove a user by returning null.
     *
     * @param mixed[] $user
     * @return mixed[]|null
     */
    public function preProcessUser(array $user): ?array
    {
        return $user;
    }

    /**
     * Allow to modify a contract before it is processed.
     *
     * This can be used to remove a contract by returning null.
     *
     * @param mixed[] $contract
     * @return mixed[]|null
     */
    public function preProcessContract(array $contract): ?array
    {
        return $contract;
    }

    /**
     * Allow to modify exported organizations.
     *
     * @param array<mixed[]> $organizations
     * @return array<mixed[]>
     */
    public function postProcessOrganizations(array $organizations): array
    {
        return $organizations;
    }

    /**
     * Allow to modify exported roles.
     *
     * @param array<mixed[]> $roles
     * @return array<mixed[]>
     */
    public function postProcessRoles(array $roles): array
    {
        return $roles;
    }

    /**
     * Allow to modify exported users.
     *
     * @param array<mixed[]> $users
     * @return array<mixed[]>
     */
    public function postProcessUsers(array $users): array
    {
        return $users;
    }

    /**
     * Allow to modify exported teams.
     *
     * @param array<mixed[]> $teams
     * @return array<mixed[]>
     */
    public function postProcessTeams(array $teams): array
    {
        return $teams;
    }

    /**
     * Allow to modify exported contracts.
     *
     * @param array<mixed[]> $contracts
     * @return array<mixed[]>
     */
    public function postProcessContracts(array $contracts): array
    {
        return $contracts;
    }

    /**
     * Allow to modify exported labels.
     *
     * @param array<mixed[]> $labels
     * @return array<mixed[]>
     */
    public function postProcessLabels(array $labels): array
    {
        return $labels;
    }

    /**
     * Allow to modify exported tickets.
     *
     * @param array<mixed[]> $tickets
     * @return array<mixed[]>
     */
    public function postProcessTickets(array $tickets): array
    {
        return $tickets;
    }
}
