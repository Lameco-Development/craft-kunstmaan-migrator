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

    private function classifierWithoutYiiInit(): LlmClassifier
    {
        $class = new ReflectionClass(LlmClassifier::class);
        /** @var LlmClassifier $classifier */
        $classifier = $class->newInstanceWithoutConstructor();

        return $classifier;
    }
}
