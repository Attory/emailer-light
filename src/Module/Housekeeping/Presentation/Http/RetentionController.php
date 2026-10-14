<?php

declare(strict_types=1);

namespace App\Module\Housekeeping\Presentation\Http;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Housekeeping\Application\Housekeeper;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Operations\Application\Contract\OperationsPolicyReader;
use App\Module\Operations\Application\Contract\OperationsPolicyWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/retention')]
final class RetentionController extends AbstractController
{
    public function __construct(private readonly Housekeeper $housekeeper, private readonly AuditSink $audit, private readonly OperationsPolicyReader $policy, private readonly OperationsPolicyWriter $policyWriter)
    {
    }

    #[Route('', name: 'admin_retention', methods: ['GET', 'HEAD'])]
    public function view(): Response
    {
        return $this->render('admin/retention.html.twig', ['policy' => $this->policy->current()]);
    }

    #[Route('/policy', name: 'admin_retention_policy', methods: ['POST'])]
    public function updatePolicy(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('retention-policy', $request->request->getString('_token'))) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $actor = $this->actor();
        try {
            $this->policyWriter->updateRetention([
                'retention_automation_enabled' => $request->request->getBoolean('retention_automation_enabled'),
                'retention_interval_hours' => $request->request->getString('retention_interval_hours'),
                'revoked_session_retention_days' => $request->request->getString('revoked_session_retention_days'),
                'queue_retention_days' => $request->request->getString('queue_retention_days'),
                'dead_letter_retention_days' => $request->request->getString('dead_letter_retention_days'),
                'health_retention_days' => $request->request->getString('health_retention_days'),
                'delivery_attempt_retention_days' => $request->request->getString('delivery_attempt_retention_days'),
                'log_retention_days' => $request->request->getString('log_retention_days'),
            ]);
            $this->audit->append(new AuditRecord($actor->id(), 'retention.policy.updated', null, 'success', ['automation_enabled' => $request->request->getBoolean('retention_automation_enabled')]));
            $this->addFlash('success', 'Retention policy updated within enforced bounds.');
        } catch (\InvalidArgumentException) {
            $this->audit->append(new AuditRecord($actor->id(), 'retention.policy.updated', null, 'failure'));
            $this->addFlash('error', 'Retention policy was not accepted.');
        }

        return $this->redirectToRoute('admin_retention', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/run', name: 'admin_retention_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('retention-run', $request->request->getString('_token'))) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $counts = $this->housekeeper->run(!$request->request->getBoolean('apply'));
        $actor = $this->actor();
        $this->audit->append(new AuditRecord($actor->id(), 'retention.run', null, 'success', $counts));
        $this->addFlash('success', 'Bounded retention run completed.');

        return $this->redirectToRoute('admin_retention', status: Response::HTTP_SEE_OTHER);
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
