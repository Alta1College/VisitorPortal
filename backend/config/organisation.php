<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
|--------------------------------------------------------------------------
| Organisation configuration (fork)
|--------------------------------------------------------------------------
|
| Install-time defaults for organisation settings that upstream has no
| config key for. Administrators override these from the admin panel
| ("Organisation settings"); stored values are overlaid onto config() at
| boot by App\Settings\SettingsServiceProvider. Leave values empty here:
| organisation-specific values belong in the deployment, not in core.
|
*/

return [

    'contact_email' => env('ORGANISATION_CONTACT_EMAIL'),

    'contact_phone' => env('ORGANISATION_CONTACT_PHONE'),

    'address' => env('ORGANISATION_ADDRESS'),

    // Design tokens exposed as CSS variables via /brand.css. Hex colours (#rrggbb) or null.
    'colors' => [
        'primary' => env('BRANDING_COLOR_PRIMARY'),
        'secondary' => env('BRANDING_COLOR_SECONDARY'),
        'accent' => env('BRANDING_COLOR_ACCENT'),
        'foreground' => env('BRANDING_COLOR_FOREGROUND'),
        'background' => env('BRANDING_COLOR_BACKGROUND'),
    ],

    // Address visitors' phones use to reach the portal (QR codes). Empty: the current request's address.
    'public_url' => env('VISITOR_PUBLIC_URL'),

    // Wording shown to visitors on kiosk and mobile sign-in (consumed from Phase 5 onwards).
    'visitor_experience' => [
        'welcome_heading' => env('VISITOR_WELCOME_HEADING'),
        'welcome_message' => env('VISITOR_WELCOME_MESSAGE'),
        'visitor_type_question' => env('VISITOR_TYPE_QUESTION'),
        'show_greeting' => env('VISITOR_SHOW_GREETING'),
        'returning_visitors' => env('VISITOR_RETURNING'),
        'email_sign_out_link' => env('VISITOR_EMAIL_SIGN_OUT_LINK'),
        'heading_details' => null,
        'heading_host' => null,
        'heading_questions' => null,
        'heading_policies' => null,
        'heading_confirm' => null,
        'heading_done' => null,
        'sign_in_label' => env('VISITOR_SIGN_IN_LABEL'),
        'sign_out_label' => env('VISITOR_SIGN_OUT_LABEL'),
        'visitor_instructions' => env('VISITOR_INSTRUCTIONS'),
        'sign_in_confirmation' => env('VISITOR_SIGN_IN_CONFIRMATION'),
        'sign_out_confirmation' => env('VISITOR_SIGN_OUT_CONFIRMATION'),
        // "HH:MM" in each site's timezone (e.g. 18:00), "off" or empty for none. Sites can override.
        'auto_sign_out_time' => env('VISITOR_AUTO_SIGN_OUT_TIME'),
    ],

    // User recorded as host when a visitor type needs no host, or the visitor does not pick
    // one where it is optional (e.g. deliveries). Usually a reception account. Set per site.
    'reception_host_user_id' => env('RECEPTION_HOST_USER_ID'),

    // Days to keep audit events before visitorportal:audit:purge removes them.
    'audit_retention_days' => (int) env('AUDIT_RETENTION_DAYS', 1095),

    // Days to keep visitor evidence files (photos, signatures, uploads) before
    // visitorportal:evidence:purge removes them. The answer row stays until the visit is purged.
    'evidence_retention_days' => (int) env('EVIDENCE_RETENTION_DAYS', 365),

];
