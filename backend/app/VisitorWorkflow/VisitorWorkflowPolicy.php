<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitorWorkflow;

use App\Models\User;
use App\Support\ForkPermissions;
use App\VisitorWorkflow\Models\FormField;
use App\VisitorWorkflow\Models\Policy;
use App\VisitorWorkflow\Models\VisitorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Authorisation for visitor types, form fields and policies. Registered explicitly: without a
 * policy, Filament would allow every action. Records that history depends on cannot be
 * deleted, only deactivated.
 */
class VisitorWorkflowPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(ForkPermissions::MANAGE_VISITOR_WORKFLOW);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Model $model): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return match (true) {
            $model instanceof VisitorType => ! DB::table('visit_visitor')->where('visitor_type_id', $model->getKey())->exists(),
            $model instanceof Policy => ! $model->versions()->exists(),
            // Answers keep a snapshot, so a field can go, unless another field's
            // show-when rule depends on it (that field would silently never show).
            $model instanceof FormField => ! FormField::query()
                ->whereKeyNot($model->getKey())
                ->whereNotNull('conditions')
                ->get(['id', 'conditions'])
                ->contains(fn (FormField $other): bool => in_array($model->key, array_column($other->conditions['rules'] ?? [], 'field'), true)),
            default => true,
        };
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Model $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    public function replicate(User $user, Model $model): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return $this->viewAny($user);
    }
}
