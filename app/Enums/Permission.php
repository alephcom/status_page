<?php

namespace App\Enums;

/**
 * Permissions granted through Spatie roles. Which role gets which is set in
 * UserRole::permissions(); run `php artisan statuspage:sync-roles` after
 * changing it.
 */
enum Permission: string
{
    case AccessAdmin = 'admin.access';
    case ViewPrivate = 'components.view-private';
    case ManageIncidents = 'incidents.manage';
    case ManageMaintenance = 'maintenance.manage';
    case ViewSlaReports = 'sla.view';
    case ManageOwnApiTokens = 'api-tokens.manage-own';
    case ManageAllApiTokens = 'api-tokens.manage-all';
    case OverrideComponents = 'components.override';
    case ManageComponents = 'components.manage';
    case ManageSubscribers = 'subscribers.manage';
    case ManageTelegram = 'telegram.manage';
    case ManageWebhooks = 'webhooks.manage';
    case ManageUsers = 'users.manage';
    case ManageSettings = 'settings.manage';
    case ViewAuditLog = 'audit-log.view';
    case ReceiveMonitoringAlerts = 'monitoring.alerts';
}
