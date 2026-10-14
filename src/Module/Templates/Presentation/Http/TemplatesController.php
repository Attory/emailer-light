<?php

declare(strict_types=1);

namespace App\Module\Templates\Presentation\Http;

use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Templates\Application\TemplateAssetManager;
use App\Module\Templates\Application\TemplateManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/templates')]
final class TemplatesController extends AbstractController
{
    public function __construct(
        private readonly TemplateManager $templates,
        private readonly TemplateAssetManager $assets,
    ) {
    }

    #[Route('', name: 'admin_templates', methods: ['GET', 'HEAD'])]
    public function templates(): Response
    {
        return $this->render('admin/templates.html.twig', ['templates' => $this->templates->list($this->user())]);
    }

    #[Route('/create', name: 'admin_template_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->csrf($request, 'template-create')) {
            return $this->badRequest();
        }
        try {
            $id = $this->templates->create($this->user(), ...$this->content($request));
            $this->addFlash('success', 'Template and immutable version created.');

            return $this->redirectToRoute('admin_template_versions', ['id' => $id], Response::HTTP_SEE_OTHER);
        } catch (\Throwable) {
            $this->addFlash('error', 'Template creation was not accepted.');

            return $this->redirectToRoute('admin_templates', status: Response::HTTP_SEE_OTHER);
        }
    }

    #[Route('/{id}/edit', name: 'admin_template_edit', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'HEAD', 'POST'])]
    public function edit(Request $request, string $id): Response
    {
        return $this->templateForm($request, $id, false);
    }

    #[Route('/{id}/duplicate', name: 'admin_template_duplicate', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'HEAD', 'POST'])]
    public function duplicate(Request $request, string $id): Response
    {
        return $this->templateForm($request, $id, true);
    }

    private function templateForm(Request $request, string $id, bool $duplicate): Response
    {
        try {
            $template = $this->templates->draftInfo($this->user(), $id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
        $operation = $duplicate ? 'duplicate' : 'edit';
        if ($request->isMethod('POST')) {
            if (!$this->csrf($request, 'template-'.$operation.'-'.$id)) {
                return $this->badRequest();
            }
            try {
                if ($duplicate) {
                    $id = $this->templates->duplicate($this->user(), $id, $request->request->getString('name'));
                    $this->addFlash('success', 'Template duplicated as a new draft.');
                } else {
                    $this->templates->edit($this->user(), $id, $request->request->getString('name'), $request->request->getString('subject'), $request->request->getString('preheader'), $request->request->getString('html_body'), $request->request->getString('text_body'));
                    $this->addFlash('success', 'Template saved as a new draft. Existing sent content is unchanged.');
                }

                return $this->redirectToRoute('admin_template_versions', ['id' => $id], Response::HTTP_SEE_OTHER);
            } catch (\Throwable) {
                $this->addFlash('error', 'Template change was not accepted. Check the new name and supported template content.');

                return $this->redirectToRoute('admin_template_'.$operation, ['id' => $id], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('admin/template-edit.html.twig', ['template' => $template, 'operation' => $operation]);
    }

    #[Route('/{id}/versions', name: 'admin_template_versions', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'HEAD'])]
    public function versions(string $id): Response
    {
        return $this->render('admin/template-versions.html.twig', ['template_id' => $id, 'versions' => $this->templates->versions($this->user(), $id)]);
    }

    #[Route('/{id}/versions/create', name: 'admin_template_version_create', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function createVersion(Request $request, string $id): Response
    {
        if (!$this->csrf($request, 'template-version-create-'.$id)) {
            return $this->badRequest();
        }
        try {
            $content = $this->content($request);
            array_shift($content);
            $this->templates->addVersion($this->user(), $id, ...$content);
            $this->addFlash('success', 'Immutable template version created.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Template version was not accepted.');
        }

        return $this->redirectToRoute('admin_template_versions', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/versions/{versionId}/publish', name: 'admin_template_publish', requirements: ['id' => '[0-9a-f-]{36}', 'versionId' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function publish(Request $request, string $id, string $versionId): Response
    {
        if (!$this->csrf($request, 'template-publish-'.$versionId)) {
            return $this->badRequest();
        }
        try {
            $this->templates->publish($this->user(), $id, $versionId);
            $this->addFlash('success', 'Template version published.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Template publication was not accepted.');
        }

        return $this->redirectToRoute('admin_template_versions', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/archive', name: 'admin_template_archive', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function archive(Request $request, string $id): Response
    {
        if (!$this->csrf($request, 'template-archive-'.$id)) {
            return $this->badRequest();
        }
        try {
            $this->templates->archive($this->user(), $id);
            $this->addFlash('success', 'Template archived.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Template archive was not accepted.');
        }

        return $this->redirectToRoute('admin_templates', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/versions/{versionId}/preview', name: 'admin_template_preview', requirements: ['versionId' => '[0-9a-f-]{36}'], methods: ['GET', 'HEAD'])]
    public function preview(string $versionId): Response
    {
        return $this->render('admin/template-preview.html.twig', ['preview' => $this->templates->preview($this->user(), $versionId)]);
    }

    #[Route('/versions/{versionId}/test', name: 'admin_template_test', requirements: ['versionId' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function test(Request $request, string $versionId): Response
    {
        if (!$this->csrf($request, 'template-test-'.$versionId)) {
            return $this->badRequest();
        }
        try {
            $this->templates->fakeTest($this->user(), $versionId);
            $this->addFlash('success', 'Fake-only test validation passed. No message was sent.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Fake-only test validation was not accepted.');
        }

        return $this->redirectToRoute('admin_templates', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/assets/upload', name: 'admin_template_asset_upload', methods: ['POST'])]
    public function uploadAsset(Request $request): Response
    {
        if (!$this->csrf($request, 'template-asset-upload')) {
            return $this->badRequest();
        }
        $file = $request->files->get('image');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', 'Image upload was not accepted.');

            return $this->redirectToRoute('admin_templates', status: Response::HTTP_SEE_OTHER);
        }
        try {
            $asset = $this->assets->store($this->user(), $file->getPathname());

            return $this->render('admin/template-asset.html.twig', ['asset' => $asset]);
        } catch (\Throwable) {
            $this->addFlash('error', 'Image upload was not accepted.');

            return $this->redirectToRoute('admin_templates', status: Response::HTTP_SEE_OTHER);
        }
    }

    private function user(): AdministrativeActor
    {
        $user = $this->getUser();
        if (!$user instanceof AdministrativeActor) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function csrf(Request $request, string $id): bool
    {
        return $this->isCsrfTokenValid($id, $request->request->getString('_token'));
    }

    private function badRequest(): Response
    {
        return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
    }

    /** @return list<string> */
    private function content(Request $request): array
    {
        return [
            $request->request->getString('name'),
            $request->request->getString('subject'),
            $request->request->getString('preheader'),
            $request->request->getString('html_body'),
            $request->request->getString('text_body'),
        ];
    }
}
