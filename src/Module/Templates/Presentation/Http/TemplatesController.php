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
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/admin/templates')]
final class TemplatesController extends AbstractController
{
    public function __construct(
        private readonly TemplateManager $templates,
        private readonly TemplateAssetManager $assets,
        private readonly CsrfTokenManagerInterface $tokens,
    ) {
    }

    #[Route('', name: 'admin_templates', methods: ['GET', 'HEAD'])]
    public function templates(): Response
    {
        return $this->renderCreateEditor();
    }

    #[Route('/assets', name: 'admin_template_assets', methods: ['GET', 'HEAD'])]
    public function assetGallery(Request $request): Response
    {
        try {
            $before = $request->query->getString('before');

            return $this->json($this->assets->page($this->user(), '' === $before ? null : $before));
        } catch (AccessDeniedException $exception) {
            throw $exception;
        } catch (\InvalidArgumentException|\Symfony\Component\HttpFoundation\Exception\BadRequestException) {
            return $this->json(['errors' => ['This image page is not valid. Refresh the gallery and try again.']], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable) {
            return $this->json(['errors' => ['Saved images could not be loaded. Try refreshing the gallery. Your editor content is unchanged.']], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/create', name: 'admin_template_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->csrf($request, 'template-create')) {
            return $this->formError($request, 'create', '', null, 'The page security check expired. Your entries are still here. Reload the editor and restore your draft before trying again.', Response::HTTP_BAD_REQUEST);
        }
        try {
            $id = $this->templates->create($this->user(), ...$this->content($request));

            return $this->saved($request, $id, 'create');
        } catch (AccessDeniedException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            return $this->formError($request, 'create', '', null, TemplateEditorMessages::save($exception), TemplateEditorMessages::status($exception));
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
                return $this->formError($request, $operation, $id, $template, 'The page security check expired. Your entries are still here. Reload the editor and restore your draft before trying again.', Response::HTTP_BAD_REQUEST);
            }
            try {
                if ($duplicate) {
                    $id = $this->templates->duplicate($this->user(), $id, $request->request->getString('name'));
                } else {
                    $this->templates->edit($this->user(), $id, $request->request->getString('name'), $request->request->getString('subject'), $request->request->getString('preheader'), $request->request->getString('html_body'), $request->request->getString('text_body'));
                }

                return $this->saved($request, $id, $operation);
            } catch (AccessDeniedException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                return $this->formError($request, $operation, $id, $template, TemplateEditorMessages::save($exception), TemplateEditorMessages::status($exception));
            }
        }

        $values = $this->storedValues($template);
        if ($duplicate) {
            $values['name'] = '';
        }

        return $this->editForm($id, $template, $operation, $values);
    }

    #[Route('/{id}/versions', name: 'admin_template_versions', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'HEAD'])]
    public function versions(string $id): Response
    {
        return $this->versionsForm($id);
    }

    #[Route('/{id}/versions/create', name: 'admin_template_version_create', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function createVersion(Request $request, string $id): Response
    {
        if (!$this->csrf($request, 'template-version-create-'.$id)) {
            return $this->formError($request, 'version', $id, null, 'The page security check expired. Your entries are still here. Reload the editor and restore your draft before trying again.', Response::HTTP_BAD_REQUEST);
        }
        try {
            $content = $this->content($request);
            array_shift($content);
            $this->templates->addVersion($this->user(), $id, ...$content);

            return $this->saved($request, $id, 'version');
        } catch (AccessDeniedException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            return $this->formError($request, 'version', $id, null, TemplateEditorMessages::save($exception), TemplateEditorMessages::status($exception));
        }
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

        return $this->redirectToRoute('admin_template_versions', ['id' => $id] + $this->campaignReturn($request), Response::HTTP_SEE_OTHER);
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

        return $this->redirectToRoute('admin_templates', $this->campaignReturn($request), Response::HTTP_SEE_OTHER);
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

        return $this->redirectToRoute('admin_templates', $this->campaignReturn($request), Response::HTTP_SEE_OTHER);
    }

    #[Route('/assets/upload', name: 'admin_template_asset_upload', methods: ['POST'])]
    public function uploadAsset(Request $request): Response
    {
        $token = $request->request->getString('_asset_token', $request->request->getString('_token'));
        if (!$this->isCsrfTokenValid('template-asset-upload', $token)) {
            return $this->uploadForm($request, 'The image upload security check expired. Your entries are unchanged. Reload the editor and restore your draft before retrying.', null, Response::HTTP_BAD_REQUEST);
        }
        $file = $request->files->get('image');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->uploadForm($request, TemplateEditorMessages::upload(), null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $asset = $this->assets->store($this->user(), $file->getPathname());

            return $this->uploadForm($request, null, $asset);
        } catch (AccessDeniedException $exception) {
            throw $exception;
        } catch (\InvalidArgumentException) {
            return $this->uploadForm($request, TemplateEditorMessages::upload(), null, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable) {
            return $this->uploadForm($request, 'The upload could not be confirmed. Your entries are unchanged. Refresh the saved-image gallery before retrying; the image may already have been saved.', null, Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /** @return array<string, string> */
    private function values(?Request $request = null): array
    {
        $values = [];
        foreach (['name', 'subject', 'preheader', 'html_body', 'text_body'] as $field) {
            $values[$field] = $request?->request->getString($field) ?? '';
        }

        return $values;
    }

    /** @param array<string, mixed> $template
     * @return array<string, string>
     */
    private function storedValues(array $template): array
    {
        $values = $this->values();
        foreach ($values as $field => $_value) {
            $value = $template[$field] ?? '';
            if (!\is_string($value)) {
                throw new \UnexpectedValueException('Stored template content is invalid.');
            }
            $values[$field] = $value;
        }

        return $values;
    }

    /** @param array<string, string> $values
     * @return array<string, mixed>
     */
    private function editor(string $kind, string $id, array $values, ?string $error, ?string $notice, ?string $selected, bool $unsaved): array
    {
        $route = match ($kind) {
            'create' => 'admin_template_create',
            'edit' => 'admin_template_edit',
            'version' => 'admin_template_version_create',
            default => throw new \LogicException('Unknown template editor.'),
        };
        $purpose = match ($kind) {
            'create' => 'template-create',
            'edit' => 'template-edit-'.$id,
            'version' => 'template-version-create-'.$id,
        };
        $galleryError = null;
        try {
            $gallery = $this->assets->page($this->user());
        } catch (AccessDeniedException $exception) {
            throw $exception;
        } catch (\Throwable) {
            $gallery = ['items' => [], 'next_cursor' => null];
            $galleryError = 'Saved images could not be loaded. Your editor content is still here; retry loading the gallery.';
        }

        return ['placeholders' => \App\Module\Templates\Application\TemplateContentPolicy::FIELD_EXAMPLES, 'kind' => $kind, 'id' => $id, 'values' => $values, 'error' => $error, 'notice' => $notice,
            'unsaved' => $unsaved || null !== $error, 'selected_asset' => $selected, 'gallery' => $gallery, 'gallery_error' => $galleryError,
            'action' => $this->generateUrl($route, '' === $id ? [] : ['id' => $id]), 'csrf_purpose' => $purpose,
            'key' => $kind.'-'.$id, 'actor' => $this->user()->id()];
    }

    /** @param array<string, string> $values */
    private function renderCreateEditor(array $values = [], ?string $error = null, int $status = Response::HTTP_OK, ?string $notice = null, ?string $selected = null, bool $unsaved = false): Response
    {
        $templates = [];
        try {
            $templates = $this->templates->list($this->user());
        } catch (AccessDeniedException $exception) {
            throw $exception;
        } catch (\Throwable) {
            $error ??= 'Saved templates could not be loaded. Your entries are still here. Try again when the application status is healthy.';
        }

        return $this->render('admin/templates.html.twig', ['templates' => $templates,
            'editor' => $this->editor('create', '', array_replace($this->values(), $values), $error, $notice, $selected, $unsaved)], new Response(status: $status));
    }

    /** @param array<string, mixed> $template
     * @param array<string, string> $values
     */
    private function editForm(string $id, array $template, string $operation, array $values, ?string $error = null, int $status = Response::HTTP_OK, ?string $notice = null, ?string $selected = null, bool $unsaved = false): Response
    {
        return $this->render('admin/template-edit.html.twig', ['template' => $template, 'operation' => $operation,
            'values' => $values, 'form_error' => $error,
            'editor' => 'edit' === $operation ? $this->editor('edit', $id, $values, $error, $notice, $selected, $unsaved) : null], new Response(status: $status));
    }

    /** @param array<string, string> $values */
    private function versionsForm(string $id, array $values = [], ?string $error = null, int $status = Response::HTTP_OK, ?string $notice = null, ?string $selected = null, bool $unsaved = false): Response
    {
        return $this->render('admin/template-versions.html.twig', ['template_id' => $id,
            'versions' => $this->templates->versions($this->user(), $id),
            'editor' => $this->editor('version', $id, array_replace($this->values(), $values), $error, $notice, $selected, $unsaved)], new Response(status: $status));
    }

    /** @return array<string, string> */
    private function campaignReturn(Request $request): array
    {
        $id = $request->request->getString('return_campaign', $request->query->getString('return_campaign'));

        return 1 === preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/', $id) ? ['return_campaign' => $id] : [];
    }

    private function wantsJson(Request $request): bool
    {
        return \in_array('application/json', $request->getAcceptableContentTypes(), true);
    }

    /** @param array<string, mixed>|null $template */
    private function formError(Request $request, string $kind, string $id, ?array $template, string $message, int $status = Response::HTTP_UNPROCESSABLE_ENTITY): Response
    {
        if ($this->wantsJson($request)) {
            return $this->json(['errors' => [$message]], $status);
        }
        $values = $this->values($request);
        if ('version' === $kind) {
            return $this->versionsForm($id, $values, $message, $status);
        }
        if (null !== $template && \in_array($kind, ['edit', 'duplicate'], true)) {
            return $this->editForm($id, $template, $kind, $values, $message, $status);
        }

        return $this->renderCreateEditor($values, $message, $status);
    }

    private function saved(Request $request, string $id, string $kind): Response
    {
        $return = $request->request->getString('return_campaign', $request->query->getString('return_campaign'));
        $context = 1 === preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/', $return) ? ['return_campaign' => $return] : [];
        $redirect = $this->generateUrl('admin_template_versions', ['id' => $id] + $context);
        if ($this->wantsJson($request)) {
            $next = 'version' === $kind ? 'version' : 'edit';
            $purpose = 'version' === $next ? 'template-version-create-'.$id : 'template-edit-'.$id;

            return $this->json(['saved' => true, 'redirect' => $redirect, 'next_editor' => [
                'kind' => $next, 'id' => $id, 'key' => $next.'-'.$id,
                'action' => $this->generateUrl('version' === $next ? 'admin_template_version_create' : 'admin_template_edit', ['id' => $id] + $context),
                'url' => $this->generateUrl('version' === $next ? 'admin_template_versions' : 'admin_template_edit', ['id' => $id] + $context),
                'token' => $this->tokens->getToken($purpose)->getValue(),
            ]]);
        }
        $this->addFlash('success', 'Template draft saved. Publication is a separate action; previously sent content is unchanged.');

        return $this->redirect($redirect, Response::HTTP_SEE_OTHER);
    }

    /** @param array{id: string, url: string, width: int, height: int}|null $asset */
    private function uploadForm(Request $request, ?string $error, ?array $asset, int $status = Response::HTTP_OK): Response
    {
        if ($this->wantsJson($request)) {
            return $this->json(null === $error ? ['asset' => $asset] : ['errors' => [$error]], $status);
        }
        $values = $this->values($request);
        $kind = $request->request->getString('_editor_kind', 'create');
        $id = $request->request->getString('_editor_id');
        $notice = null === $asset ? null : 'Image uploaded and saved in the gallery. Choose Insert image when you are ready; your template entries are unchanged.';
        if (\in_array($kind, ['edit', 'version'], true) && 1 === preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/', $id)) {
            try {
                $template = $this->templates->draftInfo($this->user(), $id);
            } catch (\InvalidArgumentException) {
                return $this->renderCreateEditor($values, $error ?? 'The earlier template is unavailable. Your entries are retained here; keep a copy before choosing another template.', $status, $notice, $asset['id'] ?? null, true);
            }

            return 'edit' === $kind
                ? $this->editForm($id, $template, 'edit', $values, $error, $status, $notice, $asset['id'] ?? null, true)
                : $this->versionsForm($id, $values, $error, $status, $notice, $asset['id'] ?? null, true);
        }

        return $this->renderCreateEditor($values, $error, $status, $notice, $asset['id'] ?? null, true);
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
