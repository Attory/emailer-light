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

        return $this->render('admin/campaigns.html.twig', [
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
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        try {
            $content = null;
            $templateVersion = $request->request->getString('template_version');
            if ('upload' === $request->request->getString('template_mode', 'existing')) {
                $file = $request->files->get('template_file');
                if ($file instanceof UploadedFile) {
                    if (!$file->isValid() || $file->getSize() > 200_000) {
                        throw new \InvalidArgumentException('Template upload is invalid.');
                    }
                    $html = file_get_contents($file->getPathname());
                } else {
                    $html = $request->request->getString('html');
                }
                if (!\is_string($html) || '' === trim($html) || \strlen($html) > 200_000 || !mb_check_encoding($html, 'UTF-8')) {
                    throw new \InvalidArgumentException('Template content is invalid.');
                }
                $content = ['name' => $request->request->getString('template_name'), 'subject' => $request->request->getString('subject'), 'preheader' => $request->request->getString('preheader'), 'html' => $html];
            }
            $csv = $request->files->get('recipient_csv');
            $csvPath = null;
            if ($csv instanceof UploadedFile) {
                if (!$csv->isValid()) {
                    throw new \InvalidArgumentException('Recipient CSV upload is invalid.');
                }
                $csvPath = $csv->getPathname();
            }
            $id = $this->campaigns->prepareDraft($this->user(), $request->request->getString('name'), $templateVersion, $content, $csvPath, $this->rate($request));
            $this->addFlash('success', 'Campaign draft created. Review recipient counts and send a test before approval.');

            return $this->redirectToRoute('admin_campaign', ['id' => $id], Response::HTTP_SEE_OTHER);
        } catch (\Throwable) {
            $this->addFlash('error', 'Draft creation was not accepted. Check the template, CSV, campaign name and rate.');

            return $this->index($request)->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }
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
                return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
            }
            try {
                $this->campaigns->updateDraft($this->user(), $id, $request->request->getString('name'), $request->request->getString('template_version'), $this->rate($request));
                $this->addFlash('success', 'Draft saved. A fresh test is required before approval.');

                return $this->redirectToRoute('admin_campaign', ['id' => $id], Response::HTTP_SEE_OTHER);
            } catch (\Throwable) {
                $this->addFlash('error', 'Draft could not be saved.');
            }
        }
        $options = $this->campaigns->options($this->user());
        $options['templates'] = $this->templates->campaignOptions($this->user());

        return $this->render('admin/campaign-edit.html.twig', ['campaign' => $campaign, 'options' => $options]);
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
        } catch (\Throwable) {
            $this->addFlash('error', 'This action is unavailable in the current campaign state. Refresh the campaign and check Doctor.');
        }

        return $this->redirectToRoute('admin_campaign', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    /** @return array<string, string> */
    private function retainedValues(Request $request): array
    {
        $values = [];
        foreach (['name', 'template_name', 'template_version', 'subject', 'preheader', 'html', 'messages_per_minute', 'template_mode'] as $field) {
            $values[$field] = mb_substr($request->request->getString($field), 0, 200_000);
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
