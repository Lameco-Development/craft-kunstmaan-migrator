<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\analyze;

use lameco\kunstmaanmigrator\analyze\LlmClassifier;
use lameco\kunstmaanmigrator\source\CraftGraphContract;
use lameco\kunstmaanmigrator\source\KunstmaanGraphContract;
use lameco\kunstmaanmigrator\tests\support\GraphFixtureFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class LlmClassifierGraphPromptTest extends TestCase
{
    public function testBatchPromptIncludesNormalizedGraphPairAndRelationIntentVocabulary(): void
    {
        $classifier = $this->classifierWithoutYiiInit();
        $method = new ReflectionMethod($classifier, 'buildBatchPrompt');

        [, $user] = $method->invoke(
            $classifier,
            [
                [
                    'table' => 'lameco_websitebundle_newspages',
                    'column' => 'employee_id',
                    'targetEntryType' => 'newsPage',
                    'fillRate' => 100,
                    'sqlType' => 'int',
                    'samples' => [97],
                    'sourceRef' => KunstmaanGraphContract::pageRootRef('App\\Entity\\Pages\\NewsPage') . '.employee',
                ],
            ],
            [
                'newsPage' => [
                    ['handle' => 'caseTeamMembers', 'type' => 'Entries', 'sources' => ['entryType:employee']],
                ],
            ],
            'legacy markdown',
            'craft markdown',
            GraphFixtureFactory::kunstmaanNewsEmployeeGraph(),
            GraphFixtureFactory::craftNewsHomeGraph(),
        );

        self::assertStringContainsString('<kunstmaanGraph>', $user);
        self::assertStringContainsString('<craftGraph>', $user);
        self::assertStringContainsString(KunstmaanGraphContract::GRAPH_VERSION, $user);
        self::assertStringContainsString(CraftGraphContract::GRAPH_VERSION, $user);
        self::assertStringContainsString('kunstmaan.entity:App\\\\Entity\\\\Employee', $user);
        self::assertStringContainsString(CraftGraphContract::craftEntryTypeRef('newsPage'), $user);
        self::assertStringContainsString('sourceRef=kunstmaan.page:App\\Entity\\Pages\\NewsPage.employee', $user);
    }

    public function testBatchPromptSystemNamesExactRelationIntents(): void
    {
        $classifier = $this->classifierWithoutYiiInit();
        $method = new ReflectionMethod($classifier, 'buildBatchPrompt');

        [$system] = $method->invoke(
            $classifier,
            [],
            [],
            '',
            '',
            GraphFixtureFactory::kunstmaanNewsEmployeeGraph(),
            GraphFixtureFactory::craftNewsHomeGraph(),
        );

        self::assertStringContainsString('relationIntent', $system);
        self::assertStringContainsString('reference, promote, embed, drop, out_of_scope', $system);
        self::assertStringContainsString('stable graph refs', $system);
        self::assertStringContainsString('handlerOptions', $system);
        self::assertStringContainsString('joinTranslation', $system);
    }

    public function testHandlerOptionsSanitizerPreservesRelationAndSplitNameOptions(): void
    {
        $classifier = $this->classifierWithoutYiiInit();
        $method = new ReflectionMethod($classifier, 'sanitiseHandlerOptions');

        $options = $method->invoke($classifier, [
            'stateSource' => 'App_Entity_Pages_EmployeePage',
            'joinTranslation' => [
                'table' => 'lameco_websitebundle_employee_pages',
                'sourceColumn' => 'employee_id',
                'targetColumn' => 'id',
            ],
            'part' => 'firstName',
            'unknown' => 'drop me',
        ]);

        self::assertSame([
            'stateSource' => 'App_Entity_Pages_EmployeePage',
            'part' => 'firstName',
            'joinTranslation' => [
                'table' => 'lameco_websitebundle_employee_pages',
                'sourceColumn' => 'employee_id',
                'targetColumn' => 'id',
            ],
        ], $options);
    }

    public function testLayoutBlockSanitizerKeepsHeaderBlockObjectShape(): void
    {
        $classifier = $this->classifierWithoutYiiInit();
        $method = new ReflectionMethod($classifier, 'sanitiseLayoutBlockSpec');

        $block = $method->invoke($classifier, [
            'fieldHandle' => 'headerCase',
            'blockType' => 'headerCaseHero',
            'title' => '{title}',
            'fields' => [
                'headerCase.image' => ['source' => 'image_id', 'handler' => 'asset'],
                'ckeditorDefault' => ['source' => 'summary', 'handler' => 'ckeditor'],
                'bad' => ['source' => 'ignored', 'handler' => 'notAHandler'],
            ],
        ], [
            'headerCase' => ['headerCaseHero'],
        ], true, true);

        self::assertSame([
            'blockType' => 'headerCaseHero',
            'fieldHandle' => 'headerCase',
            'title' => '{title}',
            'fields' => [
                'image' => ['source' => 'image_id', 'handler' => 'asset'],
                'ckeditorDefault' => ['source' => 'summary', 'handler' => 'ckeditor'],
            ],
        ], $block);
    }

    public function testLayoutBlockSanitizerDropsLiteralBodyWrapTitle(): void
    {
        $classifier = $this->classifierWithoutYiiInit();
        $method = new ReflectionMethod($classifier, 'sanitiseLayoutBlockSpec');

        $block = $method->invoke($classifier, [
            'fieldHandle' => 'ckeditorDefault',
            'blockType' => 'generalContentBlock',
            'title' => 'Vacancy Details',
        ], [
            'pageBuilder' => ['generalContentBlock'],
        ], false, false);

        self::assertSame([
            'blockType' => 'generalContentBlock',
            'fieldHandle' => 'ckeditorDefault',
        ], $block);
    }

    public function testGraphProposalFieldsArePreservedWhenValid(): void
    {
        $classifier = $this->classifierWithoutYiiInit();
        $method = new ReflectionMethod($classifier, 'normaliseGraphProposalFields');

        $fields = $method->invoke($classifier, [
            'sourceRef' => KunstmaanGraphContract::entityRef('App\\Entity\\Employee'),
            'targetRef' => CraftGraphContract::craftEntryTypeRef('teamMember'),
            'relationIntent' => 'promote',
        ]);

        self::assertSame([
            'sourceRef' => KunstmaanGraphContract::entityRef('App\\Entity\\Employee'),
            'targetRef' => CraftGraphContract::craftEntryTypeRef('teamMember'),
            'relationIntent' => 'promote',
        ], $fields);
    }

    public function testFallbackGraphProposalFieldsUsesInputSourceAndTargetHandle(): void
    {
        $classifier = $this->classifierWithoutYiiInit();
        $method = new ReflectionMethod($classifier, 'fallbackGraphProposalFields');

        $fields = $method->invoke(
            $classifier,
            [],
            ['sourceRef' => KunstmaanGraphContract::pageRootRef('App\\Entity\\Pages\\NewsPage') . '.employee'],
            'newsPage',
            'caseTeamMembers',
            'map',
            'relation',
        );

        self::assertSame(KunstmaanGraphContract::pageRootRef('App\\Entity\\Pages\\NewsPage') . '.employee', $fields['sourceRef']);
        self::assertSame(CraftGraphContract::craftFieldRef('newsPage', 'caseTeamMembers'), $fields['targetRef']);
        self::assertSame('reference', $fields['relationIntent']);
    }

    public function testDecodeLlmJsonPayloadAcceptsFencedJsonWithTrailingText(): void
    {
        $classifier = $this->classifierWithoutYiiInit();
        $method = new ReflectionMethod($classifier, 'decodeLlmJsonPayload');

        $decoded = $method->invoke(
            $classifier,
            "Here is the mapping:\n```json\n{\"proposals\":[{\"column\":\"content\"}]}\n```\nDone.",
            'test response',
        );

        self::assertSame(['proposals' => [['column' => 'content']]], $decoded);
    }

    private function classifierWithoutYiiInit(): LlmClassifier
    {
        $class = new ReflectionClass(LlmClassifier::class);
        /** @var LlmClassifier $classifier */
        $classifier = $class->newInstanceWithoutConstructor();

        return $classifier;
    }
}
