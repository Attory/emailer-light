<?php

declare(strict_types=1);

namespace App\Tests\Integration\Light;

use App\Module\Identity\Application\RoutePolicyRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

final class SurfaceTest extends KernelTestCase
{
    public function testRemovedRipeRoutesAndServicesCannotBeReached(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);
        $paths = [];
        $adminRoutes = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            $paths[] = $route->getPath();
            if (str_starts_with($route->getPath(), '/admin')) {
                $adminRoutes[$name] = $route->getMethods();
            }
            $controller = $route->getDefault('_controller');
            self::assertFalse(\is_string($controller) && 1 === preg_match('/(?:Contacts|Mfa|Reauth|UserManagement|SecurityLevel)/i', $controller), $name);
        }
        $joined = implode("\n", $paths);
        foreach (['/admin/lists', '/admin/contacts', '/admin/users', '/admin/security', '/mfa', '/reauth', '/password/reset', '/newsletter/signup'] as $removed) {
            self::assertStringNotContainsString($removed, $joined);
        }
        self::assertContains('/admin/campaigns/{id}/recipients/replace', $paths);
        self::assertContains('/admin/campaigns/{id}/recipients/delete', $paths);
        self::assertContains('/login', $paths);
        $policy = self::getContainer()->get(RoutePolicyRegistry::class);
        self::assertInstanceOf(RoutePolicyRegistry::class, $policy);
        $expectedNames = array_keys($adminRoutes);
        $policyNames = array_keys($policy->all());
        sort($expectedNames);
        sort($policyNames);
        self::assertSame($expectedNames, $policyNames);
        foreach ($adminRoutes as $name => $methods) {
            self::assertSame($methods, $policy->methods($name));
        }
    }
}
