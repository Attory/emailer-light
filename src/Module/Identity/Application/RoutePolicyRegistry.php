<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

/** Explicit fail-closed policy for every authenticated administration route. */
final class RoutePolicyRegistry
{
    /** @var array<string, list<string>> */
    private const METHODS = [
        'admin_analytics' => ['GET', 'HEAD'],
        'admin_analytics_policy' => ['POST'],
        'admin_audit' => ['GET', 'HEAD'],
        'admin_backup_create' => ['POST'],
        'admin_backup_policy' => ['POST'],
        'admin_backups' => ['GET', 'HEAD'],
        'admin_campaign' => ['GET', 'HEAD'],
        'admin_campaign_approve' => ['POST'],
        'admin_campaign_cancel' => ['POST'],
        'admin_campaign_create' => ['POST'],
        'admin_campaign_edit' => ['GET', 'HEAD', 'POST'],
        'admin_campaign_guide' => ['GET', 'HEAD'],
        'admin_campaign_pause' => ['POST'],
        'admin_campaign_progress' => ['GET', 'HEAD'],
        'admin_campaign_queue' => ['POST'],
        'admin_campaign_rate' => ['POST'],
        'admin_campaign_recipients_delete' => ['POST'],
        'admin_campaign_recipients_replace' => ['POST'],
        'admin_campaign_resume' => ['POST'],
        'admin_campaign_review' => ['POST'],
        'admin_campaign_rsvp' => ['POST'],
        'admin_campaign_schedule' => ['POST'],
        'admin_campaign_test' => ['POST'],
        'admin_campaigns' => ['GET', 'HEAD'],
        'admin_dashboard' => ['GET', 'HEAD'],
        'admin_delivery_settings' => ['GET', 'HEAD'],
        'admin_delivery_settings_update' => ['POST'],
        'admin_doctor' => ['GET', 'HEAD'],
        'admin_doctor_acknowledge' => ['POST'],
        'admin_doctor_export' => ['GET', 'HEAD'],
        'admin_doctor_run' => ['POST'],
        'admin_logout' => ['POST'],
        'admin_mail_settings' => ['GET', 'HEAD'],
        'admin_mail_settings_update' => ['POST'],
        'admin_mail_test_connection' => ['POST'],
        'admin_mail_send_test' => ['POST'],
        'admin_password_change' => ['POST'],
        'admin_profile' => ['GET', 'HEAD'],
        'admin_release' => ['GET', 'HEAD'],
        'admin_retention' => ['GET', 'HEAD'],
        'admin_retention_policy' => ['POST'],
        'admin_retention_run' => ['POST'],
        'admin_rsvp' => ['GET', 'HEAD'],
        'admin_rsvp_export' => ['POST'],
        'admin_session_revoke' => ['POST'],
        'admin_sessions' => ['GET', 'HEAD'],
        'admin_suppression_add' => ['POST'],
        'admin_suppressions' => ['GET', 'HEAD'],
        'admin_template_archive' => ['POST'],
        'admin_template_asset_upload' => ['POST'],
        'admin_template_assets' => ['GET', 'HEAD'],
        'admin_template_create' => ['POST'],
        'admin_template_duplicate' => ['GET', 'HEAD', 'POST'],
        'admin_template_edit' => ['GET', 'HEAD', 'POST'],
        'admin_template_preview' => ['GET', 'HEAD'],
        'admin_template_publish' => ['POST'],
        'admin_template_test' => ['POST'],
        'admin_template_version_create' => ['POST'],
        'admin_template_versions' => ['GET', 'HEAD'],
        'admin_templates' => ['GET', 'HEAD'],
    ];

    /** @return list<string>|null */
    public function methods(string $route): ?array
    {
        return self::METHODS[$route] ?? null;
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return self::METHODS;
    }
}
