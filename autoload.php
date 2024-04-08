<?php

// This file is part of GLPI To Bileto.
// Copyright 2024 Probesys
// SPDX-License-Identifier: AGPL-3.0-or-later

spl_autoload_register(
    function ($class_name) {
        $app_namespace = 'App';
        $app_path = __DIR__;

        if (str_starts_with($class_name, $app_namespace)) {
            $class_name = substr($class_name, strlen($app_namespace) + 1);
            $class_path = str_replace('\\', '/', $class_name) . '.php';
            include $app_path . '/src/' . $class_path;
        }
    }
);
