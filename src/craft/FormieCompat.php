<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\craft;

/**
 * The three places where VerbbFormieGateway has to tell Formie 3 from Formie 4.
 *
 * Decided by what the object offers, not by a version string, so the gateway
 * keeps working on either major without importing anything that one of them
 * lacks. Each method is a no-op or a fallback on the version that does not
 * need it, never an error: a cutover load must not die on a plugin upgrade.
 */
final class FormieCompat
{
    /**
     * Formie 3 deletes only the pages, rows and fields the layout names in
     * `setDeletedItems()`. Formie 4 removed the method: `Fields::saveLayout()`
     * diffs the saved layout against the stored one and deletes the rest itself.
     *
     * @param callable(): array{fields: list<int>, rows: list<int>, pages: list<int>} $items
     */
    public static function recordDeletedItems(object $layout, callable $items): void
    {
        if (method_exists($layout, 'setDeletedItems')) {
            $layout->setDeletedItems($items());
        }
    }

    /**
     * Formie 3's `Submission::afterSave()` sends every status-condition
     * notification when a save changes the status and `isNewSubmission` is
     * false — which a create with an explicit status always is. Formie 4 sends
     * nothing from a plain element save (side effects belong to its submission
     * workflow) and has no such property; assigning it would throw.
     */
    public static function markNewSubmission(object $submission): void
    {
        if (property_exists($submission, 'isNewSubmission')) {
            $submission->isNewSubmission = true;
        }
    }

    /**
     * The folder id Formie itself puts a new upload on this file field in, for
     * this submission: Formie 4's `getUploadFolderForSubmission()`, Formie 3's
     * `resolveDynamicPathToFolderId()`. Null when the field offers neither.
     */
    public static function uploadFolderId(object $field, object $submission): ?int
    {
        if (method_exists($field, 'getUploadFolderForSubmission')) {
            $folder = $field->getUploadFolderForSubmission($submission);

            return $folder === null ? null : (int) $folder->id;
        }

        if (method_exists($field, 'resolveDynamicPathToFolderId')) {
            return (int) $field->resolveDynamicPathToFolderId($submission);
        }

        return null;
    }
}
