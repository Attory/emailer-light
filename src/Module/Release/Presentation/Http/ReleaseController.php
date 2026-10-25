<?php

declare(strict_types=1);

namespace App\Module\Release\Presentation\Http;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReleaseController extends AbstractController
{
    public function __construct(private readonly Connection $connection, private readonly string $version, private readonly string $releasesDirectory)
    {
    }

    #[Route('/admin/release', name: 'admin_release', methods: ['GET', 'HEAD'])]
    public function view(): Response
    {
        $migration = $this->connection->fetchOne('SELECT version FROM doctrine_migration_versions ORDER BY version DESC LIMIT 1');

        return $this->render('admin/release.html.twig', ['version' => $this->version, 'schema_version' => \is_string($migration) ? substr($migration, -14) : 'unknown', 'immutable_pointer' => '' !== $this->releasesDirectory && is_link($this->releasesDirectory.'/current')]);
    }
}
