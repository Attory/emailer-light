<?php

declare(strict_types=1);

namespace App\Module\Configuration\Presentation\Http;

use App\Module\Configuration\Application\DeliveryPolicyManager;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/settings/delivery')]
final class DeliveryPolicyController extends AbstractController
{
    public function __construct(private readonly DeliveryPolicyManager $policy)
    {
    }

    #[Route('', name: 'admin_delivery_settings', methods: ['GET', 'HEAD'])]
    public function view(Request $request): Response
    {
        return $this->render('admin/delivery-settings.html.twig', ['policy' => $this->policy->view($this->actor())]);
    }

    #[Route('/update', name: 'admin_delivery_settings_update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delivery-settings-update', $request->request->getString('_token'))) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        try {
            $this->policy->update($this->actor(), $this->integer($request, 'messages_per_minute'), $this->integer($request, 'worker_concurrency'), $this->integer($request, 'retry_limit'), $this->integer($request, 'maximum_campaign_size'));
            $this->addFlash('success', 'Delivery limits saved. Campaigns also keep their own pacing limit.');
        } catch (\InvalidArgumentException) {
            $this->addFlash('error', 'Use 1–600 messages per minute, 1–16 concurrent workers, 1–10 delivery attempts and 1–100000 recipients per campaign.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Delivery limits could not be saved. Try again or check Doctor.');
        }

        return $this->redirectToRoute('admin_delivery_settings', status: Response::HTTP_SEE_OTHER);
    }

    private function integer(Request $request, string $field): int
    {
        $value = $request->request->getString($field);
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException('Enter a whole number.');
        }

        return (int) $value;
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
