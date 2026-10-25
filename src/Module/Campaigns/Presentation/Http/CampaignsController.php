<?php

declare(strict_types=1);

namespace App\Module\Campaigns\Presentation\Http;

use App\Module\Campaigns\Application\CampaignManager;
use App\Module\Campaigns\Application\CampaignRecipientCsv;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Templates\Application\Contract\CampaignTemplatePreparation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/campaigns')]
final class CampaignsController extends AbstractController
{
    public function __construct(
        private readonly CampaignManager $campaigns,
        private readonly CampaignRecipientCsv $recipients,
        private readonly CampaignTemplatePreparation $templates,
    ) {
    }

    #[Route('', name: 'admin_campaigns', methods: ['GET', 'HEAD'])]
    public function index(Request $request): Response
    {
        $options = $this->campaigns->options($this->user());
        $options['templates'] = $this->templates->campaignOptions($this->user());

        $selected = $request->query->getString('template');
        if ('' !== $selected && !\in_array($selected, array_column($options['templates'], 'id'), true)) {
            return new Response('Selection is unavailable.', Response::HTTP_BAD_REQUEST);
        }

        return $this->render('admin/campaigns.html.twig', [
            'selected_template' => $selected,
            'campaigns' => $this->campaigns->list($this->user()),
            'options' => $options,
            'limits' => $this->campaigns->deliveryLimits(),
            'form_values' => $this->retainedValues($request),
        ]);
    }

    #[Route('/guide', name: 'admin_campaign_guide', methods: ['GET', 'HEAD'])]
    public function guide(): Response
    {
        return $this->render('admin/campaign-guide.html.twig');
    }

    #[Route('/create', name: 'admin_campaign_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->validCsrf($request, 'campaign-create')) {
            return $this->draftError($request, 'The security check expired. Your entries and selected CSV are still here. Sign in again in another tab, then choose Refresh security check here before retrying.', Response::HTTP_BAD_REQUEST, null);
        }

        return $this->saveDraft($request, null);
    }

    #[Route('/{id}', name: 'admin_campaign', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'HEAD'])]
    public function detail(string $id): Response
    {
        try {
            return $this->render('admin/campaign.html.twig', ['campaign' => $this->campaigns->detail($this->user(), $id)]);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
    }

    #[Route('/{id}/progress', name: 'admin_campaign_progress', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'HEAD'])]
    public function progress(string $id): Response
    {
        try {
            return $this->json($this->campaigns->progress($this->user(), $id));
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
    }

    #[Route('/{id}/edit', name: 'admin_campaign_edit', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'HEAD', 'POST'])]
    public function edit(Request $request, string $id): Response
    {
        try {
            $campaign = $this->campaigns->detail($this->user(), $id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
        if (!\in_array($campaign['state'], ['draft', 'review'], true)) {
            $this->addFlash('error', 'Approved campaign content and recipients are immutable.');

            return $this->redirectToRoute('admin_campaign', ['id' => $id], Response::HTTP_SEE_OTHER);
        }
        if ($request->isMethod('POST')) {
            if (!$this->validCsrf($request, 'campaign-edit-'.$id)) {
                return $this->draftError($request, 'The security check expired. Your entries and selected CSV are still here. Sign in again in another tab, then choose Refresh security check here before retrying.', Response::HTTP_BAD_REQUEST, $id);
            }

            return $this->saveDraft($request, $id);
        }
        $options = $this->campaigns->options($this->user());
        $options['templates'] = $this->templates->campaignOptions($this->user());

        return $this->render('admin/campaign-edit.html.twig', ['campaign' => $campaign, 'options' => $options, 'form_values' => null]);
    }

    private function saveDraft(Request $request, ?string $id): Response
    {
        try {
            $file = $request->files->get('recipient_csv');
            if ($file instanceof UploadedFile && !$file->isValid()) {
                throw new \InvalidArgumentException('Recipient CSV upload is invalid.');
            }
            $csvPath = $file instanceof UploadedFile ? $file->getPathname() : null;
            $result = $this->campaigns->saveDraft($this->user(), $id, $request->request->getString('name'), $request->request->getString('template_version'), $this->rate($request), $csvPath);
            $saved = $result['id'];
            $message = 'Campaign draft saved. Nothing was published, approved or sent.';
            if (null !== $result['csv_counts']) {
                $counts = $result['csv_counts'];
                $message .= \sprintf(' CSV: %d unique recipients saved; %d duplicate rows; %d invalid rows left out; %d currently suppressed. Review the recipient count before approval and replace the CSV if corrections are needed.', $counts['imported'], $counts['duplicate'], $counts['invalid'], $counts['suppressed']);
            }
            $this->addFlash('success', $message);
            $redirect = 'templates' === $request->request->getString('_destination')
                ? $this->generateUrl('admin_templates', ['return_campaign' => $saved])
                : $this->generateUrl('admin_campaign_edit', ['id' => $saved]);
            if (\in_array('application/json', $request->getAcceptableContentTypes(), true)) {
                return $this->json(['saved' => true, 'id' => $saved, 'redirect' => $redirect, 'campaign_url' => $this->generateUrl('admin_campaign_edit', ['id' => $saved])]);
            }

            return $this->redirect($redirect, Response::HTTP_SEE_OTHER);
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $failure) {
            throw $failure;
        } catch (\Throwable $failure) {
            return $this->draftError($request, CampaignDraftMessages::save($failure), $failure instanceof \InvalidArgumentException || $failure instanceof \DomainException || $failure instanceof \Symfony\Component\HttpFoundation\Exception\BadRequestException ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_SERVICE_UNAVAILABLE, $id);
        }
    }

    private function draftError(Request $request, string $message, int $status, ?string $id): Response
    {
        if (\in_array('application/json', $request->getAcceptableContentTypes(), true)) {
            return $this->json(['saved' => false, 'errors' => [$message]], $status);
        }
        $this->addFlash('error', $message.' On the server form, select the CSV file again before retrying. The live editor retains the selected file automatically.');
        if (null === $id) {
            return $this->index($request)->setStatusCode($status);
        }
        $options = $this->campaigns->options($this->user());
        $options['templates'] = $this->templates->campaignOptions($this->user());

        return $this->render('admin/campaign-edit.html.twig', ['campaign' => $this->campaigns->detail($this->user(), $id), 'options' => $options, 'form_values' => $this->retainedValues($request)], new Response(status: $status));
    }

    #[Route('/{id}/recipients/replace', name: 'admin_campaign_recipients_replace', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function replaceRecipients(Request $request, string $id): Response
    {
        if (!$this->validCsrf($request, 'campaign-recipients-replace-'.$id)) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        try {
            $file = $request->files->get('recipient_csv');
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                throw new \InvalidArgumentException('Recipient CSV upload is invalid.');
            }
            $counts = $this->recipients->replace($this->user(), $id, $file->getPathname());
            $this->addFlash('success', \sprintf('Recipient snapshot replaced: %d imported, %d duplicates, %d invalid, %d currently suppressed.', $counts['imported'], $counts['duplicate'], $counts['invalid'], $counts['suppressed']));
        } catch (\Throwable) {
            $this->addFlash('error', 'Recipient CSV was not accepted. The previous campaign recipients remain unchanged.');
        }

        return $this->redirectToRoute('admin_campaign', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/recipients/delete', name: 'admin_campaign_recipients_delete', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function deleteRecipients(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-recipients-delete', fn () => $this->recipients->delete($this->user(), $id), 'Campaign recipients deleted. Upload a new CSV before review.');
    }

    #[Route('/{id}/rate', name: 'admin_campaign_rate', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function configureRate(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-rate', fn () => $this->campaigns->configureRate($this->user(), $id, $this->rate($request)), 'Sending rate saved.');
    }

    #[Route('/{id}/review', name: 'admin_campaign_review', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function review(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-review', fn () => $this->campaigns->submitForReview($this->user(), $id), 'Campaign submitted for review.');
    }

    #[Route('/{id}/rsvp', name: 'admin_campaign_rsvp', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function rsvp(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-rsvp', fn () => $this->campaigns->configureRsvp($this->user(), $id, $request->request->getBoolean('enabled')), 'Campaign RSVP setting updated.');
    }

    #[Route('/{id}/test', name: 'admin_campaign_test', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function test(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-test', fn () => $this->campaigns->requestTest($this->user(), $id), 'Test queued for the administrator.');
    }

    #[Route('/{id}/approve', name: 'admin_campaign_approve', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function approve(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-approve', fn () => $this->campaigns->approve($this->user(), $id, $request->request->getInt('reviewed_revision'), $request->request->getBoolean('waive_test')), 'Campaign approved with immutable recipient snapshot.');
    }

    #[Route('/{id}/schedule', name: 'admin_campaign_schedule', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function schedule(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-schedule', fn () => $this->campaigns->schedule($this->user(), $id, $this->campaigns->scheduleTime($request->request->getString('scheduled_at'))), 'Campaign scheduled.');
    }

    #[Route('/{id}/queue', name: 'admin_campaign_queue', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function queue(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-queue', fn () => $this->campaigns->queue($this->user(), $id), 'Campaign delivery queued.');
    }

    #[Route('/{id}/pause', name: 'admin_campaign_pause', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function pause(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-pause', fn () => $this->campaigns->pause($this->user(), $id), 'Campaign paused.');
    }

    #[Route('/{id}/resume', name: 'admin_campaign_resume', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function resume(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-resume', fn () => $this->campaigns->resume($this->user(), $id), 'Campaign resumed.');
    }

    #[Route('/{id}/cancel', name: 'admin_campaign_cancel', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function cancel(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-cancel', fn () => $this->campaigns->cancel($this->user(), $id), 'Campaign cancelled.');
    }

    private function mutate(Request $request, string $id, string $token, callable $operation, string $success): Response
    {
        if (!$this->validCsrf($request, $token.'-'.$id)) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        try {
            $operation();
            $this->addFlash('success', $success);
        } catch (\Throwable $failure) {
            $this->addFlash('error', CampaignDraftMessages::save($failure));
        }

        return $this->redirectToRoute('admin_campaign', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    /** @return array<string, string> */
    private function retainedValues(Request $request): array
    {
        $input = $request->request->all();
        $values = [];
        foreach (['name', 'template_version', 'messages_per_minute', 'audience_mode'] as $field) {
            $values[$field] = isset($input[$field]) && \is_string($input[$field]) ? mb_substr($input[$field], 0, 200_000) : '';
        }

        return $values;
    }

    private function rate(Request $request): ?int
    {
        $value = $request->request->getString('messages_per_minute');
        if ('' === $value) {
            return null;
        }
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException('Sending rate must be a whole number.');
        }

        return (int) $value;
    }

    private function validCsrf(Request $request, string $id): bool
    {
        return $this->isCsrfTokenValid($id, $request->request->getString('_token'));
    }

    private function user(): AuthenticatedUser
    {
        $user = $this->getUser();
        if (!$user instanceof AuthenticatedUser) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
