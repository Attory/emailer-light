<?php

declare(strict_types=1);

namespace App\Module\Configuration\Presentation\Http;

use App\Module\Configuration\Application\Contract\MailTestFailure;
use App\Module\Configuration\Application\MailConfigurationManager;
use App\Module\Configuration\Application\MailConnectionProof;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/settings/mail')]
final class MailConfigurationController extends AbstractController
{
    public function __construct(private readonly MailConfigurationManager $mail, private readonly ?LoggerInterface $logger = null)
    {
    }

    #[Route('', name: 'admin_mail_settings', methods: ['GET', 'HEAD'])]
    public function view(Request $request): Response
    {
        return $this->render('admin/mail-settings.html.twig', $this->mailContext($request));
    }

    #[Route('/update', name: 'admin_mail_settings_update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('mail-settings-update', $request->request->getString('_token'))) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        try {
            $tested = $this->mail->configureSecureSmtp($this->actor(), $request->request->getString('host'), $request->request->getInt('port'), $request->request->getString('encryption'), $request->request->getString('username'), $request->request->getString('password'), $request->request->getString('sender_address'), $request->request->getString('sender_name'), $request->request->getString('reply_to'), $request->request->getBoolean('one_click_dkim_confirmed'), MailConnectionProof::fromSession($request->getSession()->get('mail.connection.proof')));
            $request->getSession()->remove('mail.connection.proof');
            $this->addFlash('success', $tested ? 'SMTP settings saved with the successful connection test. No email was sent.' : 'SMTP settings saved. Test the current connection before sending. No email was sent.');
        } catch (\InvalidArgumentException) {
            return $this->testResponse($request, false, 'SMTP settings were not saved. Check the hostname, port, encryption, username and sender address. Enter the SMTP password when changing the host or username; entered non-secret settings are retained.', 'incomplete', Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $error) {
            $failure = new MailTestFailure('internal', diagnostics: ['phase' => 'save_configuration', 'fault_type' => $error::class, 'fault_code' => $error->getCode()]);
            $this->logger?->error('smtp_configuration_save_failed', $failure->safeDiagnostics());

            return $this->testResponse($request, false, $failure->getMessage().' Settings were not saved.', 'internal', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->redirectToRoute('admin_mail_settings', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/test-connection', name: 'admin_mail_test_connection', methods: ['POST'])]
    public function testConnection(Request $request): Response
    {
        $source = $request->request->getString('_configuration_source', 'current');
        $token = 'saved' === $source ? $request->request->getString('_token') : $request->request->getString('_connection_token', $request->request->getString('_token'));
        if (!$this->isCsrfTokenValid('mail-test', $token)) {
            return $this->testResponse($request, false, 'The security check expired. Sign in or refresh Mail settings in another tab, then retry. Your entries have not been saved.', 'internal', Response::HTTP_BAD_REQUEST);
        }
        // A newer test supersedes earlier evidence, including a failed retest.
        $request->getSession()->remove('mail.connection.proof');
        $category = 'saved';
        try {
            if ('saved' === $source) {
                $this->mail->test($this->actor(), null);
                $message = 'Saved settings tested successfully. No email was sent. Unsaved form changes were not applied.';
            } elseif ('current' === $source) {
                $result = $this->mail->testCurrentConfiguration($this->actor(), $request->request->getString('host'), $request->request->getInt('port'), $request->request->getString('encryption'), $request->request->getString('username'), $request->request->getString('password'), $request->request->getString('sender_address'), $request->request->getString('sender_name'), $request->request->getString('reply_to'), $request->request->getBoolean('one_click_dkim_confirmed'));
                $request->getSession()->set('mail.connection.proof', $result['proof']->sessionValue());
                $category = $result['saved'] ? 'saved' : 'current_unsaved';
                $message = $result['saved'] ? 'Saved settings tested successfully. The current form matches them. No email was sent.' : 'Current settings tested successfully—save to apply. No email was sent.';
            } else {
                throw new MailTestFailure('incomplete');
            }

            return $this->testResponse($request, true, $message, $category, Response::HTTP_OK);
        } catch (MailTestFailure $failure) {
            $this->logger?->warning('smtp_form_test_failed', $failure->safeDiagnostics());

            return $this->testResponse($request, false, $failure->getMessage(), $failure->category, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $error) {
            $failure = new MailTestFailure('internal', diagnostics: ['phase' => 'current_configuration', 'fault_type' => $error::class, 'fault_code' => $error->getCode()]);
            $this->logger?->error('smtp_form_test_failed', $failure->safeDiagnostics());

            return $this->testResponse($request, false, $failure->getMessage(), 'internal', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/send-test', name: 'admin_mail_send_test', methods: ['POST'])]
    public function sendTest(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('mail-test', $request->request->getString('_token'))) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        try {
            $this->mail->test($this->actor(), $request->request->getString('destination'));
            $this->addFlash('success', 'The requested test email was accepted by your provider. No campaign was sent.');
        } catch (\DomainException $failure) {
            $this->addFlash('error', $failure->getMessage().' No email was sent.');
        } catch (MailTestFailure $failure) {
            $this->addFlash('error', $failure->getMessage());
        } catch (\Throwable $error) {
            $failure = new MailTestFailure('internal', diagnostics: ['phase' => 'explicit_test_message', 'fault_type' => $error::class, 'fault_code' => $error->getCode()], uncertainMessage: true);
            $this->logger?->error('smtp_message_test_failed', $failure->safeDiagnostics());
            $this->addFlash('error', $failure->getMessage());
        }

        return $this->redirectToRoute('admin_mail_settings', status: Response::HTTP_SEE_OTHER);
    }

    private function testResponse(Request $request, bool $success, string $message, string $category, int $status): Response
    {
        if (str_contains($request->headers->get('Accept', ''), 'application/json')) {
            return $this->json(['success' => $success, 'message' => $message, 'category' => $category], $status, ['Cache-Control' => 'private, no-store']);
        }
        $response = $this->render('admin/mail-settings.html.twig', [...$this->mailContext($request, 'saved' !== $request->request->getString('_configuration_source')), 'connection_result' => ['success' => $success, 'message' => $message]]);
        $response->setStatusCode($status);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /** @return array<string, mixed> */
    private function mailContext(Request $request, bool $retain = false): array
    {
        $saved = $this->mail->view($this->actor());
        $mail = $saved;
        if ($retain) {
            foreach (['host' => 'smtp_host', 'port' => 'smtp_port', 'encryption' => 'encryption_mode', 'username' => 'smtp_username', 'sender_address' => 'sender_address', 'sender_name' => 'sender_name', 'reply_to' => 'reply_to_address'] as $input => $field) {
                $mail[$field] = $request->request->getString($input);
            }
            $mail['one_click_dkim_confirmed'] = $request->request->getBoolean('one_click_dkim_confirmed');
        }

        return ['mail' => $mail, 'saved_mail' => $saved, 'form_retained' => $retain];
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
