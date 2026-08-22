<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\load;

use Lameco\KumaCompile\Compile\FormCompiler;
use Lameco\KumaCompile\Compile\Transforms;
use lameco\kunstmaanmigrator\adapters\GatedAdapter;
use lameco\kunstmaanmigrator\adapters\MigrationAdapter;
use lameco\kunstmaanmigrator\craft\FormGateway;
use lameco\kunstmaanmigrator\craft\VerbbFormieGateway;
use lameco\kunstmaanmigrator\run\EnvironmentContext;
use Throwable;
use yii\base\Component;

/**
 * The `forms:` lane: a legacy form-context page becomes a Formie form.
 *
 * The mapping has declared this lane since the DSL was written and nothing ever
 * compiled it, so 495 live placements had no destination and the 289 migrated
 * `formBlock`s carry an empty relation and render as an empty shell.
 *
 * Written as an ordinary MigrationAdapter, which it could not have been before
 * EnvironmentContext: a lane that compiles from the mapping needs the mapping
 * and an open legacy connection, and `migrateAll(MigrationOptions, SiteMap)`
 * carried neither. Its configuration is declared rather than hard-coded for the
 * same reason — what a form is called, and what a submission does afterwards,
 * are a project's decisions, and the previous attempt at this lane baked both
 * into a 667-line class alongside the table names.
 */
class FormMigrationService extends Component implements MigrationAdapter
{
    use GatedAdapter;

    private const STATE_SOURCE = 'form';

    public ?MigrationStateService $stateService = null;

    public ?FormGateway $forms = null;

    public function handle(): string
    {
        return 'forms';
    }

    private function gateway(): FormGateway
    {
        return $this->forms ??= new VerbbFormieGateway();
    }

    public function migrateAll(MigrationOptions $opts, EnvironmentContext $context): MigrationReport
    {
        $report = new MigrationReport();

        if (!$this->isGateOpen($report)) {
            return $report;
        }

        if ($context->mapping === null || $context->legacy === null) {
            $report->warn('The forms lane compiles from the mapping and reads the legacy database; one of them was not supplied.');

            return $report;
        }

        if (!$this->gateway()->isAvailable()) {
            $report->warn('formie is not installed; no forms were written.');

            return $report;
        }

        $config = $this->config();
        $prefix = (string) ($config['handlePrefix'] ?? '');
        $compiler = new FormCompiler(
            $context->mapping,
            new Transforms($context->mapping->all()['transforms'] ?? []),
            $context->only,
        );

        $compiler->compile(
            $context->legacy,
            $context->name,
            function (array $record) use ($opts, $config, $prefix, $report): void {
                $this->load($record, $opts, $config, $prefix, $report);
            },
        );

        foreach ($compiler->skipped() as $reason => $count) {
            $report->warn(sprintf('%d skipped: %s', $count, $reason));
        }

        return $report;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $config
     */
    private function load(array $record, MigrationOptions $opts, array $config, string $prefix, MigrationReport $report): void
    {
        $sourceUid = (string) $record['sourceUid'];
        $handle = $this->handleFor($sourceUid, $prefix);

        $report->incr('compiled');

        if ($opts->dryRun) {
            return;
        }

        $existing = $this->stateService?->getTargetId(self::STATE_SOURCE, $sourceUid, null);

        if ($existing !== null && !$opts->force) {
            $report->incr('skipped');

            return;
        }

        $warnings = [];

        try {
            $formId = $this->gateway()->saveForm(
                $handle,
                (string) ($record['title'] ?? $handle),
                (array) ($record['fields'] ?? []),
                [
                    'submitActionMessage' => $config['submitActionMessage'] ?? null,
                    'pageLabel' => $config['pageLabel'] ?? 'Page 1',
                ],
                $warnings,
            );
        } catch (Throwable $e) {
            $report->incr('failed');
            $report->warn(sprintf('%s: %s', $sourceUid, $e->getMessage()));

            return;
        }

        foreach ($warnings as $warning) {
            $report->warn($warning);
        }

        if ($formId === null) {
            $report->incr('failed');

            return;
        }

        // The state row is what lets a `formBlock` find the form it belongs to,
        // and what makes a second run an update rather than a duplicate.
        $this->stateService?->record(
            self::STATE_SOURCE,
            $sourceUid,
            'formie_form',
            $formId,
            null,
            null,
            ['handle' => $handle, 'fields' => count((array) ($record['fields'] ?? []))],
        );

        $report->incr($existing === null ? 'created' : 'updated');
    }

    /**
     * A stable, readable handle derived from the legacy identity.
     *
     * Derived rather than taken from the page title: two legacy pages routinely
     * share a title, and a form silently overwriting another is the failure this
     * lane would otherwise ship with.
     *
     * The environment is part of it because a page id is unique within one
     * legacy database and a migration walks three. COM's PotionsLandingPage 27
     * and DE's are different pages, and without the environment the second run
     * would overwrite the first — the same class of bug as the rewriter caching
     * bare legacy ids across databases.
     */
    private function handleFor(string $sourceUid, string $prefix): string
    {
        // kuma:<ENV>:form:<Entity>:<id>
        $parts = explode(':', $sourceUid);
        $tail = [$parts[1] ?? '', $parts[3] ?? '', $parts[4] ?? ''];

        $camel = str_replace(' ', '', ucwords(strtolower(str_replace(['_', '-'], ' ', implode(' ', $tail)))));

        return $prefix === '' ? lcfirst($camel) : $prefix . ucfirst($camel);
    }
}
