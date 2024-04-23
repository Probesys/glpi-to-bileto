<?php

// This file is part of GLPI To Bileto.
// Copyright 2024 Probesys
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App;

/**
 * Provide helpful methods to manipulate arrays.
 */
class ArrayHelper
{
    /**
     * Find in an array and return (if any) the element matching the callback.
     *
     * @template TElement of mixed
     *
     * @param TElement[] $array
     * @param callable(TElement): bool $callback
     *
     * @return ?TElement
     */
    public static function find(array $array, callable $callback): mixed
    {
        foreach ($array as $item) {
            if ($callback($item)) {
                return $item;
            }
        }

        return null;
    }
}
