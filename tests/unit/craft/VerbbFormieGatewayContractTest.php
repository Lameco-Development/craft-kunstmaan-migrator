<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\craft;

use PHPUnit\Framework\TestCase;

/**
 * What VerbbFormieGateway calls on the installed verbb/formie, checked by
 * reflection (no Craft boot). A Formie release that renames one of these is
 * caught here rather than at a cutover load, where it took down every form or
 * every submission write.
 *
 * Version-specific members (setDeletedItems, isNewSubmission, the upload
 * folder resolver) go through FormieCompat and are checked per major.
 */
final class VerbbFormieGatewayContractTest extends TestCase
{
    private const FIELD_CLASSES = [
        'SingleLineText', 'MultiLineText', 'Email', 'Hidden', 'Checkboxes', 'Agree', 'Dropdown',
        'Radio', 'FileUpload', 'Phone', 'Number', 'Heading', 'Html',
    ];

    private const METHODS = [
        'verbb\formie\models\FieldLayout' => ['getFields', 'getPages', 'setPages'],
        'verbb\formie\models\FieldLayoutPage' => ['getRows', 'setRows'],
        'verbb\formie\models\FieldLayoutRow' => ['setFields'],
        'verbb\formie\elements\Form' => ['getFormLayout', 'setFormLayout', 'getFieldByHandle', 'getDefaultStatus'],
        'verbb\formie\elements\Submission' => ['setForm'],
    ];

    protected function setUp(): void
    {
        if (!class_exists('verbb\formie\Formie')) {
            self::markTestSkipped('verbb/formie is not installed.');
        }
    }

    public function testEveryFieldTypeTheGatewayMapsExists(): void
    {
        foreach (self::FIELD_CLASSES as $short) {
            self::assertTrue(class_exists('verbb\formie\fields\\' . $short), "verbb\\formie\\fields\\$short");
        }
    }

    public function testEveryMethodTheGatewayCallsExists(): void
    {
        foreach (self::METHODS as $class => $methods) {
            foreach ($methods as $method) {
                self::assertTrue(method_exists($class, $method), "$class::$method()");
            }
        }
    }

    public function testTheVersionSpecificMembersMatchTheInstalledMajor(): void
    {
        $formie4 = !method_exists('verbb\formie\models\FieldLayout', 'setDeletedItems');

        if ($formie4) {
            self::assertTrue(method_exists('verbb\formie\fields\FileUpload', 'getUploadFolderForSubmission'));
            self::assertFalse(property_exists('verbb\formie\elements\Submission', 'isNewSubmission'));
        } else {
            self::assertTrue(method_exists('verbb\formie\fields\FileUpload', 'resolveDynamicPathToFolderId'));
            self::assertTrue(property_exists('verbb\formie\elements\Submission', 'isNewSubmission'));
        }
    }

    public function testTheScheduleSettingsAnArchiveFormUsesExist(): void
    {
        foreach (['scheduleForm', 'scheduleFormStart', 'scheduleFormEnd'] as $property) {
            self::assertTrue(property_exists('verbb\formie\models\FormSettings', $property), "FormSettings::\$$property");
        }
    }
}
