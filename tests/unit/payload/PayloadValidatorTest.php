<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\payload;

use InvalidArgumentException;
use lameco\kunstmaanmigrator\payload\Payload;
use lameco\kunstmaanmigrator\payload\PayloadValidator;
use lameco\kunstmaanmigrator\payload\SchemaGateway;
use PHPUnit\Framework\TestCase;

/**
 * Fixture: deterministic stand-in for CraftSchemaGateway. Knows exactly the
 * handles the baseline payload references — anything else resolves to
 * "unknown" so every UNKNOWN_* mutation test is driven purely by the payload
 * array, not by gateway configuration.
 */
final class FakeSchemaGateway implements SchemaGateway
{
    /** @var array<string, array{id: int, handle: string}> */
    private array $sections = ['pages' => ['id' => 1, 'handle' => 'pages']];

    /** @var array<string, array{id: int, handle: string, hasTitleFormat: bool}> */
    private array $entryTypes = [
        'contentPage' => ['id' => 1, 'handle' => 'contentPage', 'hasTitleFormat' => false],
    ];

    /** @var array<string, array{id: int, handle: string}> */
    private array $sites = ['en' => ['id' => 1, 'handle' => 'en']];

    /** @var array<string, list<string>> */
    private array $fieldHandles = ['contentPage' => ['pageBuilder', 'relatedPages', 'body']];

    /** @var array<string, array<string, list<string>>> */
    private array $blockTypes = ['contentPage' => ['pageBuilder' => ['contentMediaBlock']]];

    public function sectionByHandle(string $handle): ?array
    {
        return $this->sections[$handle] ?? null;
    }

    public function entryTypeByHandle(string $handle): ?array
    {
        return $this->entryTypes[$handle] ?? null;
    }

    public function primarySite(): array { return ['id' => 11, 'handle' => 'en']; }

            public function siteByHandle(string $handle): ?array
    {
        return $this->sites[$handle] ?? null;
    }

    public function fieldHandlesFor(string $entryTypeHandle): array
    {
        return $this->fieldHandles[$entryTypeHandle] ?? [];
    }

    /** Derived from the same fixtures the other lookups use, so fakes stay consistent. */
    public function fieldSlotsFor(string $entryTypeHandle): array
    {
        $slots = [];

        foreach ($this->fieldHandlesFor($entryTypeHandle) as $handle) {
            $nested = $this->blockTypesFor($entryTypeHandle, $handle);
            $slots[$handle] = [
                'type' => $nested === [] ? 'PlainText' : 'Matrix',
                'required' => false,
                'nested' => $nested,
            ];
        }

        return $slots;
    }

    public function blockTypesFor(string $entryTypeHandle, string $fieldHandle): array
    {
        return $this->blockTypes[$entryTypeHandle][$fieldHandle] ?? [];
    }

    public function markEntryTypeTitleFormatted(string $handle): void
    {
        $this->entryTypes[$handle]['hasTitleFormat'] = true;
    }
}

final class PayloadValidatorTest extends TestCase
{
    private PayloadValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PayloadValidator(new FakeSchemaGateway());
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayloadArray(): array
    {
        return [
            'sourceUid' => 'kuma:COM:nt_page:143',
            'aliases' => ['kuma:DE:nt_page:87'],
            'section' => 'pages',
            'entryType' => 'contentPage',
            'sites' => [
                'en' => [
                    'enabled' => true,
                    'title' => 'Swyx',
                    'slug' => 'products/swyx',
                    'parentRef' => 'kuma:COM:nt_page:12',
                    'postDate' => '2024-03-01T10:00:00+00:00',
                    'fieldValues' => [
                        'pageBuilder' => [
                            [
                                'type' => 'contentMediaBlock',
                                'fields' => [
                                    'heading' => 'Swyx heading',
                                    'media' => ['_asset' => 'uploads/swyx.jpg'],
                                ],
                            ],
                        ],
                        'relatedPages' => [
                            ['_ref' => 'kuma:COM:nt_page:200'],
                        ],
                        'body' => '<p>Some body {{kuma:media:123}}</p>',
                    ],
                ],
            ],
        ];
    }

    public function testValidPayloadProducesNoViolations(): void
    {
        $violations = $this->validator->validate(Payload::fromArray($this->validPayloadArray()));
        self::assertSame([], $violations);
    }

    public function testBadSourceUidProducesViolation(): void
    {
        $raw = $this->validPayloadArray();
        $raw['sourceUid'] = 'not-a-uid';
        $violations = $this->validator->validate(Payload::fromArray($raw));
        self::assertCount(1, $violations);
        self::assertSame('BAD_SOURCE_UID', $violations[0]->code);
        self::assertSame('not-a-uid', $violations[0]->sourceUid);
    }

    public function testBadAliasProducesBadSourceUidViolation(): void
    {
        $raw = $this->validPayloadArray();
        $raw['aliases'] = ['nope'];
        $violations = $this->validator->validate(Payload::fromArray($raw));
        self::assertCount(1, $violations);
        self::assertSame('BAD_SOURCE_UID', $violations[0]->code);
    }

    public function testUnknownSectionProducesViolation(): void
    {
        $raw = $this->validPayloadArray();
        $raw['section'] = 'bogus-section';
        $violations = $this->validator->validate(Payload::fromArray($raw));
        self::assertCount(1, $violations);
        self::assertSame('UNKNOWN_SECTION', $violations[0]->code);
    }

    public function testUnknownEntryTypeProducesViolation(): void
    {
        $raw = $this->validPayloadArray();
        $raw['entryType'] = 'bogusEntryType';
        $violations = $this->validator->validate(Payload::fromArray($raw));
        self::assertCount(1, $violations);
        self::assertSame('UNKNOWN_ENTRY_TYPE', $violations[0]->code);
    }

    public function testUnknownSiteProducesViolation(): void
    {
        $raw = $this->validPayloadArray();
        $raw['sites']['zz'] = $raw['sites']['en'];
        unset($raw['sites']['en']);
        $violations = $this->validator->validate(Payload::fromArray($raw));
        self::assertCount(1, $violations);
        self::assertSame('UNKNOWN_SITE', $violations[0]->code);
    }

    public function testNoEnabledSiteProducesViolation(): void
    {
        $raw = $this->validPayloadArray();
        $raw['sites']['en']['enabled'] = false;
        $violations = $this->validator->validate(Payload::fromArray($raw));
        self::assertCount(1, $violations);
        self::assertSame('NO_ENABLED_SITE', $violations[0]->code);
    }

    public function testUnknownFieldProducesViolation(): void
    {
        $raw = $this->validPayloadArray();
        $raw['sites']['en']['fieldValues']['nope'] = 'x';
        $violations = $this->validator->validate(Payload::fromArray($raw));
        self::assertSame('UNKNOWN_FIELD', $violations[0]->code);
    }

    public function testUnknownBlockTypeProducesViolation(): void
    {
        $raw = $this->validPayloadArray();
        $raw['sites']['en']['fieldValues']['pageBuilder'][0]['type'] = 'bogusBlock';
        $violations = $this->validator->validate(Payload::fromArray($raw));
        self::assertCount(1, $violations);
        self::assertSame('UNKNOWN_BLOCK_TYPE', $violations[0]->code);
    }

    public function testMissingTitleProducesViolation(): void
    {
        $raw = $this->validPayloadArray();
        $raw['sites']['en']['title'] = null;
        $violations = $this->validator->validate(Payload::fromArray($raw));
        self::assertCount(1, $violations);
        self::assertSame('MISSING_TITLE', $violations[0]->code);
    }

    public function testMissingTitleIsSkippedWhenEntryTypeHasTitleFormat(): void
    {
        $gateway = new FakeSchemaGateway();
        $gateway->markEntryTypeTitleFormatted('contentPage');
        $validator = new PayloadValidator($gateway);

        $raw = $this->validPayloadArray();
        $raw['sites']['en']['title'] = null;
        $violations = $validator->validate(Payload::fromArray($raw));
        self::assertSame([], $violations);
    }

    public function testBadRefInFieldValuesProducesViolation(): void
    {
        $raw = $this->validPayloadArray();
        $raw['sites']['en']['fieldValues']['relatedPages'][0]['_ref'] = 'not-a-uid';
        $violations = $this->validator->validate(Payload::fromArray($raw));
        self::assertCount(1, $violations);
        self::assertSame('BAD_REF', $violations[0]->code);
    }

    public function testBadParentRefProducesViolation(): void
    {
        $raw = $this->validPayloadArray();
        $raw['sites']['en']['parentRef'] = 'not-a-uid';
        $violations = $this->validator->validate(Payload::fromArray($raw));
        self::assertCount(1, $violations);
        self::assertSame('BAD_REF', $violations[0]->code);
    }

    public function testBadDateProducesViolation(): void
    {
        $raw = $this->validPayloadArray();
        $raw['sites']['en']['postDate'] = 'not-a-date';
        $violations = $this->validator->validate(Payload::fromArray($raw));
        self::assertCount(1, $violations);
        self::assertSame('BAD_DATE', $violations[0]->code);
    }

    public function testFromArrayThrowsOnMissingSourceUid(): void
    {
        $raw = $this->validPayloadArray();
        unset($raw['sourceUid']);
        $this->expectException(InvalidArgumentException::class);
        Payload::fromArray($raw);
    }

    public function testFromArrayThrowsOnNonArraySites(): void
    {
        $raw = $this->validPayloadArray();
        $raw['sites'] = 'not-an-array';
        $this->expectException(InvalidArgumentException::class);
        Payload::fromArray($raw);
    }

    public function testFromArrayDefaultsMissingAliasesToEmptyArray(): void
    {
        $raw = $this->validPayloadArray();
        unset($raw['aliases']);
        $payload = Payload::fromArray($raw);
        self::assertSame([], $payload->aliases);
    }

    public function testTrailingNewlineInSourceUidProducesBadSourceUidViolation(): void
    {
        $raw = $this->validPayloadArray();
        $raw['sourceUid'] = "kuma:COM:nt_page:143\n";
        $violations = $this->validator->validate(Payload::fromArray($raw));
        self::assertCount(1, $violations);
        self::assertSame('BAD_SOURCE_UID', $violations[0]->code);
    }

    public function testTypelessBlockProducesUnknownBlockTypeViolation(): void
    {
        $raw = $this->validPayloadArray();
        $raw['sites']['en']['fieldValues']['pageBuilder'][] = [
            'fields' => ['heading' => 'No type key on this block'],
        ];
        $violations = $this->validator->validate(Payload::fromArray($raw));
        self::assertCount(1, $violations);
        self::assertSame('UNKNOWN_BLOCK_TYPE', $violations[0]->code);
    }

    public function testNumericRefProducesBadRefViolation(): void
    {
        $raw = $this->validPayloadArray();
        $raw['sites']['en']['fieldValues']['relatedPages'][0]['_ref'] = 123;
        $violations = $this->validator->validate(Payload::fromArray($raw));
        self::assertCount(1, $violations);
        self::assertSame('BAD_REF', $violations[0]->code);
    }

    public function testMissingTitleIsSkippedWhenSiteIsDisabled(): void
    {
        $raw = $this->validPayloadArray();
        $raw['sites']['en']['enabled'] = false;
        $raw['sites']['en']['title'] = null;
        $violations = $this->validator->validate(Payload::fromArray($raw));
        $codes = array_map(static fn (\lameco\kunstmaanmigrator\payload\Violation $v): string => $v->code, $violations);
        self::assertNotContains('MISSING_TITLE', $codes);
        self::assertContains('NO_ENABLED_SITE', $codes);
    }
}
