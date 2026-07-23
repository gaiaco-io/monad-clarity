<?php

declare(strict_types=1);

namespace Gaia\Clarity\Middlewares;

use Gaia\Clarity\Services\Request;
use Gaia\Clarity\Services\Response;
use LogicException;

/**
 * Role-based access control (ReleaseNotes_26.07.md §16). Same split as Authentication:
 * Clarity owns the *check*, the app owns the role/permission *data* — RBAC has no
 * schema of its own (no roles/permissions/role_permissions tables), just a duck-typed
 * resolver the app supplies.
 *
 * `$permissionsForUser` is expected to already union req 16.2.1 (user → role →
 * permission) and req 16.2.2 (user → direct permission) into one flat set — by the time
 * it returns, RBAC doesn't need to know or care whether a given permission came from a
 * role or a direct grant, only whether the user has it. `$permissionsForRole` (req
 * 16.2.3, optional — only needed if the app wants role-level checks independent of any
 * specific user, e.g. an admin UI listing what a role grants) is separate because it
 * answers a different question entirely.
 *
 * `can()`/`canAny()`/`canAll()` satisfy "service-level checks" (req 16.2.5) directly —
 * they're plain public methods, callable from anywhere, not just a route pipeline.
 * `guard()` (req 16.2.4 — route guards) returns a closure matching Route's middleware
 * signature; because it's a closure (not a class-string), it can close over whatever
 * config it needs directly and never runs into Route's `new $class()` zero-argument
 * constructor constraint the way a class-string middleware would.
 *
 * Not `final` — per CrossRepoContracts.md §5, extended by `app/middlewares/` with a
 * zero-argument constructor supplying the app's actual resolver(s). `forbiddenResponse()`
 * is a `protected` extension point.
 *
 * @package Gaia\Clarity\Middlewares
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
class RBAC
{
    /**
     * @param callable(string $userId): list<string> $permissionsForUser
     * @param (callable(string $role): list<string>)|null $permissionsForRole
     */
    public function __construct(
        private readonly mixed $permissionsForUser,
        private readonly mixed $permissionsForRole = null,
    ) {
    }

    public function can(string $userId, string $permission): bool
    {
        return in_array($permission, ($this->permissionsForUser)($userId), true);
    }

    /**
     * @param list<string> $permissions
     */
    public function canAny(string $userId, array $permissions): bool
    {
        $granted = ($this->permissionsForUser)($userId);

        foreach ($permissions as $permission) {
            if (in_array($permission, $granted, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An empty $permissions list returns true (standard vacuous-truth semantics for
     * "all of zero conditions hold") — pass a non-empty list at call sites where an
     * accidentally-empty $permissions would otherwise silently grant access.
     *
     * @param list<string> $permissions
     */
    public function canAll(string $userId, array $permissions): bool
    {
        $granted = ($this->permissionsForUser)($userId);

        foreach ($permissions as $permission) {
            if (!in_array($permission, $granted, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether $role itself grants $permission, independent of any specific user
     * (req 16.2.3). Requires $permissionsForRole to have been supplied at construction.
     */
    public function roleHasPermission(string $role, string $permission): bool
    {
        if ($this->permissionsForRole === null) {
            throw new LogicException('RBAC was constructed without $permissionsForRole.');
        }

        return in_array($permission, ($this->permissionsForRole)($role), true);
    }

    /**
     * A route guard for $permission: rejects with forbiddenResponse() unless
     * $resolveUserId($request) returns a user id that can($permission). $resolveUserId
     * is deliberately the caller's responsibility — RBAC has no opinion on how a request
     * maps to an authenticated user (Session cookie, bearer token, or anything else).
     *
     * @param callable(Request $request): (string|null) $resolveUserId
     * @return callable(Request, callable): Response
     */
    public function guard(string $permission, callable $resolveUserId): callable
    {
        return function (Request $request, callable $next) use ($permission, $resolveUserId): Response {
            $userId = $resolveUserId($request);

            if ($userId === null || !$this->can($userId, $permission)) {
                return $this->forbiddenResponse();
            }

            return $next($request);
        };
    }

    protected function forbiddenResponse(): Response
    {
        return Response::json(['error' => 'Forbidden'], 403);
    }
}
