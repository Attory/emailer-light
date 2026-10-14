<?php

declare(strict_types=1);

namespace App\Module\Backup\Presentation\Http;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Backup\Application\BackupEngine;
use App\Module\Backup\Application\Contract\AutomatedBackupOperations;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Operations\Application\Contract\OperationsPolicyReader;
use App\Module\Operations\Application\Contract\OperationsPolicyWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/backups')]
final class BackupController extends AbstractController
{
    public function __construct(private readonly BackupEngine $backups, private readonly AutomatedBackupOperations $automation, private readonly AuditSink $audit, private readonly OperationsPolicyReader $policy, private readonly OperationsPolicyWriter $policyWriter)
    {
    }

    #[Route('', name: 'admin_backups', methods: ['GET', 'HEAD'])]
    public function view(): Response
    {
        $policy = $this->policy->current();

        return $this->render('admin/backups.html.twig', ['backups' => $this->backups->recentRecords(), 'transfers' => $this->backups->recentTransfers(), 'policy' => $policy, 'destination_available' => $this->automation->destinationAvailable((string) $policy['backup_destination_type'])]);
    }

    #[Route('/policy', name: 'admin_backup_policy', methods: ['POST'])]
    public function updatePolicy(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('backup-policy', $request->request->getString('_token'))) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $actor = $this->actor();
        try {
            $enabled = $request->request->getBoolean('backup_automation_enabled');
            $destination = $request->request->getString('backup_destination_type');
            if ($enabled && !$this->automation->destinationAvailable($destination)) {
                throw new \InvalidArgumentException('Approved backup destination is unavailable.');
            }
            $this->policyWriter->updateBackup([
                'backup_automation_enabled' => $enabled,
                'backup_interval_hours' => $request->request->getString('backup_interval_hours'),
                'backup_retention_days' => $request->request->getString('backup_retention_days'),
                'restore_drill_interval_days' => $request->request->getString('restore_drill_interval_days'),
                'backup_destination_type' => $destination,
            ]);
            $this->audit->append(new AuditRecord($actor->id(), 'backup.policy.updated', null, 'success', ['automation_enabled' => $enabled, 'destination_type' => $destination]));
            $this->addFlash('success', 'Backup policy updated.');
        } catch (\InvalidArgumentException) {
            $this->audit->append(new AuditRecord($actor->id(), 'backup.policy.updated', null, 'failure'));
            $this->addFlash('error', 'Backup policy was not accepted.');
        }

        return $this->redirectToRoute('admin_backups', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/create', name: 'admin_backup_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('backup-create', $request->request->getString('_token'))) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $policy = $this->policy->current();
        $transferred = $request->request->getBoolean('transfer');
        $backup = $transferred ? $this->automation->createAndTransfer((string) $policy['backup_destination_type']) : $this->backups->create();
        $actor = $this->actor();
        $this->audit->append(new AuditRecord($actor->id(), 'backup.created', $backup['id'], 'success', ['byte_size' => $backup['bytes']]));
        $this->addFlash('success', $transferred ? 'Encrypted backup created, transferred, and independently verified.' : 'Encrypted backup created and verified.');

        return $this->redirectToRoute('admin_backups', status: Response::HTTP_SEE_OTHER);
    }

    private function actor(): AdministrativeActor
    {
        $actor = $this->getUser();
        if (!$actor instanceof AdministrativeActor) {
            throw $this->createAccessDeniedException();
        }

        return $actor;
    }
}
