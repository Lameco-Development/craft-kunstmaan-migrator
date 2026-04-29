<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use lameco\kunstmaanmigrator\mapping\MappingReview;
use lameco\kunstmaanmigrator\Plugin;
use yii\web\Response;

final class MappingController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    /**
     * @return array<string, mixed>
     */
    public static function utilityVariables(): array
    {
        $plugin = Plugin::getInstance();
        $path = $plugin->mappingFile->resolvePath();
        $mapping = is_file($path) ? $plugin->mappingFile->load($path) : ['proposals' => []];
        $rows = (array) ($mapping['proposals'] ?? []);
        $entities = MappingReview::pageEntities($rows);

        $request = Craft::$app->getRequest();
        $selectedEntity = $request->getIsConsoleRequest()
            ? ''
            : trim((string) $request->getQueryParam('entity', ''));
        if ($selectedEntity === '' && $entities !== []) {
            $selectedEntity = $entities[0];
        }
        $statusFilter = $request->getIsConsoleRequest()
            ? 'all'
            : MappingReview::normalizeStatusFilter((string) $request->getQueryParam('status', 'all'));
        $kindFilter = $request->getIsConsoleRequest()
            ? 'all'
            : MappingReview::normalizeKindFilter((string) $request->getQueryParam('kind', 'all'));
        $findingFilter = $request->getIsConsoleRequest()
            ? 'all'
            : MappingReview::normalizeFindingFilter((string) $request->getQueryParam('finding', 'all'));
        $searchQuery = $request->getIsConsoleRequest()
            ? ''
            : MappingReview::normalizeSearchQuery((string) $request->getQueryParam('q', ''));

        $indexedRows = [];
        if ($selectedEntity !== '') {
            $indexedRows = MappingReview::collectPageMappingRows(
                $rows,
                new MigrationFilters(entities: [$selectedEntity]),
            );
            foreach ($indexedRows as &$item) {
                $item['summary'] = MappingReview::summaryLine($item['row']);
            }
            unset($item);
            $indexedRows = MappingReview::filterRows($indexedRows, [
                'statusFilter' => $statusFilter,
                'kindFilter' => $kindFilter,
                'findingFilter' => $findingFilter,
                'searchQuery' => $searchQuery,
            ]);
        }

        return [
            'mappingPath' => $path,
            'entities' => $entities,
            'selectedEntity' => $selectedEntity,
            'statusFilter' => $statusFilter,
            'statusFilterOptions' => MappingReview::statusFilterOptions(),
            'kindFilter' => $kindFilter,
            'kindFilterOptions' => MappingReview::kindFilterOptions(),
            'findingFilter' => $findingFilter,
            'findingFilterOptions' => MappingReview::findingFilterOptions(),
            'searchQuery' => $searchQuery,
            'indexedRows' => $indexedRows,
            'summaryCounts' => self::summaryCounts($indexedRows),
            'targetOptions' => self::targetOptions(),
        ];
    }

    public function actionSetStatus(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $rowIndex = (int) $request->getRequiredBodyParam('rowIndex');
        $status = (string) $request->getRequiredBodyParam('status');
        if (!in_array($status, ['accepted', 'dropped', 'needs-review', 'proposed'], true)) {
            $this->setFailFlash('Invalid mapping status.');
            return $this->redirectBackToUtility();
        }

        $changes = ['status' => $status];
        $rationale = trim((string) $request->getBodyParam('rationale', ''));
        if ($rationale !== '') {
            $changes['rationale'] = $rationale;
        }

        $plugin = Plugin::getInstance();
        if ($plugin->mappingFile->updateRow($plugin->mappingFile->resolvePath(), $rowIndex, $changes)) {
            $this->setSuccessFlash('Mapping row updated.');
        } else {
            $this->setFailFlash('Could not update mapping row.');
        }

        return $this->redirectBackToUtility();
    }

    public function actionSaveTarget(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $rowIndex = (int) $request->getRequiredBodyParam('rowIndex');
        $kind = (string) $request->getBodyParam('kind', 'column');
        $changes = ['status' => 'accepted'];

        if ($kind === 'nodeClass' || $kind === 'taxonomy') {
            $changes['targetSection'] = trim((string) $request->getBodyParam('targetSection', ''));
            $changes['targetEntryType'] = trim((string) $request->getBodyParam('targetEntryType', ''));
        } elseif ($kind === 'pagePart') {
            $changes['targetEntryType'] = trim((string) $request->getBodyParam('targetEntryType', ''));
            $changes['targetMatrixField'] = trim((string) $request->getBodyParam('targetMatrixField', ''));
            $changes['targetBlockType'] = trim((string) $request->getBodyParam('targetBlockType', ''));
        } else {
            $changes['targetEntryType'] = trim((string) $request->getBodyParam('targetEntryType', ''));
            $changes['targetHandle'] = trim((string) $request->getBodyParam('targetHandle', ''));
            $changes['handler'] = trim((string) $request->getBodyParam('handler', ''));
        }

        $plugin = Plugin::getInstance();
        if ($plugin->mappingFile->updateRow($plugin->mappingFile->resolvePath(), $rowIndex, $changes)) {
            $this->setSuccessFlash('Mapping target saved.');
        } else {
            $this->setFailFlash('Could not save mapping target.');
        }

        return $this->redirectBackToUtility();
    }

    public function actionBatch(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAdmin();

        $request = Craft::$app->getRequest();
        $action = (string) $request->getRequiredBodyParam('batchAction');
        if (!in_array($action, ['accept', 'needs-review', 'drop', 'accept-warnings'], true)) {
            $this->setFailFlash('Invalid batch mapping action.');
            return $this->redirectBackToUtility();
        }

        $selectedRows = $this->selectedRowIndexes((array) $request->getBodyParam('selectedRows', []));
        if ($selectedRows === []) {
            $this->setFailFlash('Select at least one mapping row.');
            return $this->redirectBackToUtility();
        }

        $confirmation = trim((string) $request->getBodyParam('confirmation', ''));
        if ($action === 'drop' && $confirmation !== 'DROP SELECTED') {
            $this->setFailFlash('Type DROP SELECTED to drop selected mapping rows.');
            return $this->redirectBackToUtility();
        }
        if ($action === 'accept-warnings' && $confirmation !== 'ACCEPT WARNINGS') {
            $this->setFailFlash('Type ACCEPT WARNINGS to accept selected warning rows.');
            return $this->redirectBackToUtility();
        }

        $changes = match ($action) {
            'accept' => ['status' => 'accepted'],
            'needs-review' => ['status' => 'needs-review'],
            'drop' => [
                'status' => 'dropped',
                'rationale' => trim((string) $request->getBodyParam('rationale', 'Batch dropped from CP mapping review.')),
            ],
            'accept-warnings' => [
                'status' => 'accepted',
                'warningAccepted' => true,
                'rationale' => trim((string) $request->getBodyParam('rationale', 'Warnings explicitly accepted from CP mapping review.')),
            ],
        };

        $plugin = Plugin::getInstance();
        $updated = 0;
        foreach ($selectedRows as $rowIndex) {
            if ($plugin->mappingFile->updateRow($plugin->mappingFile->resolvePath(), $rowIndex, $changes)) {
                $updated++;
            }
        }

        if ($updated === count($selectedRows)) {
            $this->setSuccessFlash(sprintf('Updated %d mapping rows.', $updated));
        } elseif ($updated > 0) {
            $this->setFailFlash(sprintf('Updated %d of %d selected mapping rows.', $updated, count($selectedRows)));
        } else {
            $this->setFailFlash('Could not update selected mapping rows.');
        }

        return $this->redirectBackToUtility();
    }

    private function redirectBackToUtility(): Response
    {
        $request = Craft::$app->getRequest();
        $params = [];
        foreach (['entity', 'status', 'kind', 'finding', 'q'] as $key) {
            $value = trim((string) $request->getBodyParam($key, $request->getBodyParam('filter' . ucfirst($key), '')));
            if ($value !== '') {
                $params[$key] = $value;
            }
        }

        return $this->redirect(UrlHelper::cpUrl('utilities/kunstmaan-mapping', $params));
    }

    /**
     * @param list<array{index:int,row:array<string,mixed>}> $indexedRows
     * @return array<string, int>
     */
    private static function summaryCounts(array $indexedRows): array
    {
        $counts = [];
        foreach ($indexedRows as $item) {
            $status = (string) ($item['row']['status'] ?? 'unknown');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    /**
     * @return array{sections:list<string>,entryTypes:list<string>,fieldHandles:list<string>,matrixFields:list<string>,blockTypes:list<string>,handlers:list<string>}
     */
    private static function targetOptions(): array
    {
        $sections = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $handle = (string) ($section->handle ?? '');
            if ($handle !== '') {
                $sections[$handle] = true;
            }
        }

        $entryTypes = [];
        $fieldHandles = [];
        $matrixFields = [];
        $blockTypes = [];
        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
            $entryTypeHandle = (string) ($entryType->handle ?? '');
            if ($entryTypeHandle !== '') {
                $entryTypes[$entryTypeHandle] = true;
            }

            $layout = $entryType->getFieldLayout();
            if ($layout === null) {
                continue;
            }
            foreach ($layout->getCustomFields() as $field) {
                $fieldHandle = (string) ($field->handle ?? '');
                if ($fieldHandle === '') {
                    continue;
                }
                $fieldHandles[$fieldHandle] = true;
                if ($field instanceof \craft\fields\Matrix) {
                    $matrixFields[$fieldHandle] = true;
                    foreach ($field->getEntryTypes() as $blockType) {
                        $blockTypeHandle = (string) ($blockType->handle ?? '');
                        if ($blockTypeHandle !== '') {
                            $blockTypes[$blockTypeHandle] = true;
                        }
                        $blockLayout = $blockType->getFieldLayout();
                        if ($blockLayout === null) {
                            continue;
                        }
                        foreach ($blockLayout->getCustomFields() as $blockField) {
                            $blockFieldHandle = (string) ($blockField->handle ?? '');
                            if ($blockFieldHandle !== '') {
                                $fieldHandles[$fieldHandle . '.' . $blockFieldHandle] = true;
                            }
                        }
                    }
                }
            }
        }

        return [
            'sections' => self::sortedKeys($sections),
            'entryTypes' => self::sortedKeys($entryTypes),
            'fieldHandles' => self::sortedKeys($fieldHandles),
            'matrixFields' => self::sortedKeys($matrixFields),
            'blockTypes' => self::sortedKeys($blockTypes),
            'handlers' => ['asset', 'ckeditor', 'date', 'email', 'link', 'matrix', 'plain', 'relation', 'splitName', 'url'],
        ];
    }

    /**
     * @param array<string, true> $set
     * @return list<string>
     */
    private static function sortedKeys(array $set): array
    {
        $out = array_keys($set);
        sort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /**
     * @param list<mixed> $selectedRows
     * @return list<int>
     */
    private function selectedRowIndexes(array $selectedRows): array
    {
        $out = [];
        foreach ($selectedRows as $rowIndex) {
            if (!is_numeric($rowIndex)) {
                continue;
            }
            $out[(int) $rowIndex] = true;
        }

        $indexes = array_keys($out);
        sort($indexes, SORT_NUMERIC);
        return $indexes;
    }
}
