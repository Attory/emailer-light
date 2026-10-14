<?php

declare(strict_types=1);

namespace App\Module\Configuration\Presentation\Http;

use App\Module\Configuration\Application\MailConfigurationManager;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/settings/mail')]
final class MailConfigurationController extends AbstractController
{
    public function __construct(private readonly MailConfigurationManager $mail)
    {
    }

    #[Route('', name: 'admin_mail_settings', methods: ['GET', 'HEAD'])]
    public function view(Request $request): Response
    {
        return $this->render('admin/mail-settings.html.twig', ['mail' => $this->mail->view($this->actor())]);
    }

    #[Route('/update', name: 'admin_mail_settings_update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('mail-settings-update', $request->request->getString('_token'))) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        try {
            $this->mail->configureSecureSmtp($this->actor(), $request->request->getString('host'), $request->request->getInt('port'), $request->request->getString('encryption'), $request->request->getString('username'), $request->request->getString('password'), $request->request->getString('sender_address'), $request->request->getString('sender_name'), $request->request->getString('reply_to'), $request->request->getBoolean('one_click_dkim_confirmed'));
            $this->addFlash('success', 'Secure SMTP configuration updated. No provider connection was attempted.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Mail configuration was not accepted.');
        }

        return $this->redirectToRoute('admin_mail_settings', status: Response::HTTP_SEE_OTHER);
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
