<?php

declare(strict_types=1);

namespace App\Module\Audit\Presentation\Http;

use App\Module\Audit\Application\AuditReader;
use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\AuthorizationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuditController extends AbstractController
{
    public function __construct(
        private readonly AuditReader $reader,
        private readonly AuthorizationService $authorization,
    ) {
    }

    #[Route('/admin/audit', name: 'admin_audit', methods: ['GET', 'HEAD'])]
    public function index(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof AuthenticatedUser) {
            throw $this->createAccessDeniedException();
        }
        $this->authorization->assertAtLeast($user, AdministrativeRole::Administrator, 'audit.view');

        return $this->render('admin/audit.html.twig', ['events' => $this->reader->latest()]);
    }
}
