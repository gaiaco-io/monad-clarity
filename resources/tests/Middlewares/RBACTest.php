<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Middlewares;

use Gaia\Clarity\Middlewares\RBAC;
use Gaia\Clarity\Services\Request;
use Gaia\Clarity\Services\Response;
use Gaia\Clarity\Services\Route;
use LogicException;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class RBACTest extends TestCase
{
    #[After]
    public function resetRoutes(): void
    {
        Route::reset();
    }

    /** @var array<string, list<string>> */
    private const USER_PERMISSIONS = [
        'user-1' => ['posts.edit', 'posts.delete'],
        'user-2' => ['posts.edit'],
    ];

    /** @var array<string, list<string>> */
    private const ROLE_PERMISSIONS = [
        'admin' => ['posts.edit', 'posts.delete', 'users.manage'],
        'editor' => ['posts.edit'],
    ];

    private function rbac(bool $withRoles = false): RBAC
    {
        return new RBAC(
            permissionsForUser: fn (string $userId): array => self::USER_PERMISSIONS[$userId] ?? [],
            permissionsForRole: $withRoles ? fn (string $role): array => self::ROLE_PERMISSIONS[$role] ?? [] : null,
        );
    }

    private function next(): callable
    {
        return static fn (Request $request): Response => Response::text('ok');
    }

    public function testCanReturnsTrueForAGrantedPermission(): void
    {
        self::assertTrue($this->rbac()->can('user-1', 'posts.delete'));
    }

    public function testCanReturnsFalseForAnUngrantedPermission(): void
    {
        self::assertFalse($this->rbac()->can('user-2', 'posts.delete'));
    }

    public function testCanReturnsFalseForAnUnknownUser(): void
    {
        self::assertFalse($this->rbac()->can('nobody', 'posts.edit'));
    }

    public function testCanAnyIsTrueIfAtLeastOnePermissionIsGranted(): void
    {
        self::assertTrue($this->rbac()->canAny('user-2', ['posts.delete', 'posts.edit']));
    }

    public function testCanAnyIsFalseIfNoPermissionIsGranted(): void
    {
        self::assertFalse($this->rbac()->canAny('user-2', ['posts.delete', 'users.manage']));
    }

    public function testCanAllIsTrueOnlyIfEveryPermissionIsGranted(): void
    {
        self::assertTrue($this->rbac()->canAll('user-1', ['posts.edit', 'posts.delete']));
        self::assertFalse($this->rbac()->canAll('user-2', ['posts.edit', 'posts.delete']));
    }

    public function testRoleHasPermissionChecksIndependentlyOfAnyUser(): void
    {
        $rbac = $this->rbac(withRoles: true);

        self::assertTrue($rbac->roleHasPermission('editor', 'posts.edit'));
        self::assertFalse($rbac->roleHasPermission('editor', 'posts.delete'));
        self::assertTrue($rbac->roleHasPermission('admin', 'users.manage'));
    }

    public function testRoleHasPermissionThrowsWithoutARoleResolverConfigured(): void
    {
        $this->expectException(LogicException::class);

        $this->rbac(withRoles: false)->roleHasPermission('admin', 'posts.edit');
    }

    public function testGuardAllowsARequestWithTheRequiredPermission(): void
    {
        $guard = $this->rbac()->guard('posts.delete', fn (Request $r) => 'user-1');

        $response = $guard(Request::fromArrays(), $this->next());

        self::assertSame('ok', $response->content());
    }

    public function testGuardRejectsARequestWithoutTheRequiredPermission(): void
    {
        $guard = $this->rbac()->guard('posts.delete', fn (Request $r) => 'user-2');

        $response = $guard(Request::fromArrays(), $this->next());

        self::assertSame(403, $response->status());
    }

    public function testGuardRejectsWhenResolveUserIdReturnsNull(): void
    {
        $guard = $this->rbac()->guard('posts.edit', fn (Request $r) => null);

        self::assertSame(403, $guard(Request::fromArrays(), $this->next())->status());
    }

    public function testGuardForbiddenResponseIsOverridableViaSubclass(): void
    {
        $rbac = new class (fn (string $userId): array => []) extends RBAC {
            protected function forbiddenResponse(): Response
            {
                return Response::redirect('/login');
            }
        };

        $guard = $rbac->guard('posts.edit', fn (Request $r) => 'user-1');

        self::assertSame(302, $guard(Request::fromArrays(), $this->next())->status());
    }

    /**
     * The whole point of guard() is registering it as Route middleware — Route's
     * middleware array historically only accepted class-strings (`new $class()`,
     * requiring a zero-argument constructor), which a closure closing over its own
     * config can't satisfy. This exercises the real Route::dispatch() pipeline, not a
     * direct closure call, to prove that actually works rather than just asserting it
     * does in a docblock.
     */
    public function testGuardRegistersDirectlyAsRouteMiddlewareWithoutAWrapperClass(): void
    {
        $rbac = $this->rbac();

        Route::get('/admin', fn () => Response::text('secret'))
            ->middleware([$rbac->guard('posts.delete', fn (Request $r) => 'user-2')]); // lacks it

        $response = Route::dispatch(Request::fromArrays(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin']));

        self::assertSame(403, $response->status());
    }

    public function testGuardRegisteredAsRouteMiddlewareAllowsAPermittedUserThrough(): void
    {
        $rbac = $this->rbac();

        Route::get('/admin', fn () => Response::text('secret'))
            ->middleware([$rbac->guard('posts.delete', fn (Request $r) => 'user-1')]); // has it

        $response = Route::dispatch(Request::fromArrays(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin']));

        self::assertSame('secret', $response->content());
    }
}
