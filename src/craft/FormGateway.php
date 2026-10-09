<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\craft;

/**
 * The seam at verbb/formie.
 *
 * Same reason as NavigationGateway: a pass whose every write path runs into a
 * third-party static before it reaches a save is testable only against a booted
 * Craft with that plugin installed — which for the forms lane means it would
 * have shipped the way the unmerged FormMigrationService did, 667 lines with no
 * test and a hard dependency on a plugin the migrator does not require.
 *
 * Narrower than Formie's API on purpose. The lane needs to know a form exists,
 * make one, give it fields, hand back an id and the handles it chose, and file
 * a migrated submission on it; it does not need Formie's
 * element model, and a fake should not have to build one.
 */
interface FormGateway
{
    /** Whether verbb/formie is installed and booted. */
    public function isAvailable(): bool;

    /** The id of the form with this handle, or null when there is none. */
    public function formIdByHandle(string $handle): ?int;

    /**
     * Creates or updates a form, returning its id.
     *
     * @param string $handle stable handle derived from the legacy source
     * @param string $title  what an editor sees in the forms list
     * @param list<array{type: string, label: string, handle: string, required: bool, settings: array<string, mixed>}> $fields
     *        ordered; `type` is a Formie field class the gateway resolves, and
     *        a type it does not know is skipped and reported rather than fatal
     * @param array<string, mixed> $settings form-level settings — submit action,
     *        confirmation text, and the notification when one is configured
     * @param list<string> &$warnings anything the gateway declined to write
     * @param array<string, array{handle: string, type: string}> &$handles filled with
     *        the handle each written field got, keyed on its spec's `partRef` (else
     *        its position). The gateway decides handles — it folds accents and
     *        suffixes collisions — so this is the only place a caller learns them.
     */
    public function saveForm(
        string $handle,
        string $title,
        array $fields,
        array $settings,
        array &$warnings,
        array &$handles = [],
    ): ?int;

    /**
     * Creates or updates one submission on a form, returning its id.
     *
     * Not a front-end submission: no captcha, no validation, no notification,
     * no integration — a migrated lead must not email anyone or post to a CRM a
     * second time, years later.
     *
     * @param ?int $existingId the submission an earlier run wrote, to update in place
     * @param array{
     *     values: array<string, mixed>,
     *     files?: array<string, list<string>>,
     *     dateCreated: string,
     *     siteId?: ?int,
     *     ipAddress?: ?string,
     *     uploadVolume?: ?string,
     * } $submission `values` by field handle; `files` by field handle, absolute paths to
     *        copy into `uploadVolume`; `dateCreated` is the legacy `created`, kept
     * @param list<string> &$warnings anything the gateway declined to write
     */
    public function saveSubmission(?int $existingId, int $formId, array $submission, array &$warnings): ?int;
}
