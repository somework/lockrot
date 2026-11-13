<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use JsonSchema\Validator as JustinrainbowValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Info\SchemaInfo;
use Opis\JsonSchema\Keyword;
use Opis\JsonSchema\Keywords\ErrorTrait;
use Opis\JsonSchema\Parsers\KeywordParser;
use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Parsers\Vocabulary;
use Opis\JsonSchema\Schema;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\ValidationContext;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;

/**
 * The document check of the tests. Each error is one line `<property>: <keyword>: <message>`. The
 * property is the justinrainbow/json-schema property path (`findings[0].activity`, `(root)` for the
 * document). A `required` line names the missing property, and an `additionalProperties` line names
 * the object and, in its message, the extra key.
 *
 * The check runs opis/json-schema, which has no draft-04 reading: a draft-04 schema is read as
 * draft-06, and a float in an integer field fails as it does in justinrainbow/json-schema. When the
 * constant {@see JUSTINRAINBOW} is defined, the check runs justinrainbow/json-schema.
 */
final class SchemaCheck
{
    /** When a constant of this name is defined, {@see errors()} checks with justinrainbow/json-schema. */
    public const JUSTINRAINBOW = 'LOCKROT_TESTS_JUSTINRAINBOW';

    /** How a test writes a schema object for {@see errors()}: `{}` stays an object, 1.0 stays a float. */
    public const ENCODE = \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    private const DRAFT_04 = 'http://json-schema.org/draft-04/schema#';
    private const DRAFT_06 = 'http://json-schema.org/draft-06/schema#';

    /** @var array<string, string> the keyword of each justinrainbow/json-schema constraint that names it otherwise */
    private const JUSTINRAINBOW_KEYWORDS = ['additionalProp' => 'additionalProperties', 'divisibleBy' => 'multipleOf'];

    /** The keywords whose line also tells which branch failed, as in justinrainbow/json-schema. */
    private const BRANCHES = ['allOf', 'anyOf', 'oneOf'];

    /**
     * The opis extensions are off: justinrainbow/json-schema reads none of them, and the extension
     * `default` writes into the document. The draft-04 forms of opis (`exclusiveMinimum: true`) stay on.
     */
    private const OPTIONS = [
        'allowFilters' => false,
        'allowMappers' => false,
        'allowTemplates' => false,
        'allowGlobals' => false,
        'allowDefaults' => false,
        'allowSlots' => false,
        'allowKeywordValidators' => false,
        'allowPragmas' => false,
        'allowDataKeyword' => false,
    ];

    /**
     * One parsed schema per SHA-256 of its JSON. opis keeps a parsed schema by its `$id`, and a
     * strict twin or a changed copy has the `$id` of its published schema, so a validator per `$id`
     * checks a copy against the schema that loaded first.
     *
     * @var array<string, array{Validator, Schema}>
     */
    private static array $opis = [];

    /** @var array<string, string> the SHA-256 by schema JSON */
    private static array $hashes = [];

    /** @return list<string> the error lines, empty when the document is valid */
    public static function errors(string $schema, string $json): array
    {
        return \defined(self::JUSTINRAINBOW) ? self::justinrainbowErrors($schema, $json) : self::opisErrors($schema, $json);
    }

    /** @return list<string> as {@see errors()} */
    public static function opisErrors(string $schema, string $json): array
    {
        $data = self::document($json);
        self::$hashes[$schema] ??= hash('sha256', $schema);
        [$validator, $parsed] = self::$opis[self::$hashes[$schema]] ??= self::parsed($schema);
        $error = $validator->schemaValidation($data, $parsed);

        return $error === null ? [] : self::opisLines($error, new ErrorFormatter());
    }

    /** @return list<string> as {@see errors()} */
    public static function justinrainbowErrors(string $schema, string $json): array
    {
        $data = self::document($json);
        $validator = new JustinrainbowValidator();
        $validator->validate($data, json_decode($schema));
        $lines = [];
        foreach ($validator->getErrors() as $error) {
            Assert::assertIsArray($error);
            $constraint = $error['constraint'] ?? null;
            Assert::assertIsArray($constraint);
            [$name, $property, $message] = [$constraint['name'] ?? null, $error['property'] ?? null, $error['message'] ?? null];
            Assert::assertIsString($name);
            Assert::assertIsString($property);
            Assert::assertIsString($message);
            $keyword = self::JUSTINRAINBOW_KEYWORDS[$name] ?? (substr($name, -6) === 'Format' ? 'format' : $name);
            $lines[] = ($property === '' ? '(root)' : $property).': '.$keyword.': '.$message;
        }

        return $lines;
    }

    /** @return mixed */
    private static function document(string $json)
    {
        $data = json_decode($json);
        Assert::assertNotNull($data, 'valid JSON');

        return $data;
    }

    /** @return array{Validator, Schema} */
    private static function parsed(string $json): array
    {
        $schema = json_decode($json);
        if (!$schema instanceof \stdClass) {
            throw new \UnexpectedValueException('a schema must be a JSON object');
        }
        $vars = get_object_vars($schema);
        if (($vars['$schema'] ?? self::DRAFT_04) === self::DRAFT_04) {
            $schema->{'$schema'} = self::DRAFT_06;
            if (\array_key_exists('id', $vars)) {
                $schema->{'$id'} = $vars['id'];
                unset($schema->id);
            }
        }
        $loader = new SchemaLoader(new SchemaParser([], self::OPTIONS, self::integerVocabulary()));
        $validator = new Validator($loader, \PHP_INT_MAX, false);

        return [$validator, $loader->loadObjectSchema($schema)];
    }

    /** @return list<string> */
    private static function opisLines(ValidationError $error, ErrorFormatter $formatter): array
    {
        $lines = [];
        foreach ($error->subErrors() as $sub) {
            Assert::assertInstanceOf(ValidationError::class, $sub);
            array_push($lines, ...self::opisLines($sub, $formatter));
        }
        $keyword = $error->keyword();
        if ($error->subErrors() !== [] && !\in_array($keyword, self::BRANCHES, true)) {
            return $lines;
        }
        $path = array_values($error->data()->fullPath());
        if ($keyword === 'required' || $keyword === 'additionalProperties') {
            foreach (self::names($error, $keyword === 'required' ? 'missing' : 'properties') as $name) {
                $lines[] = $keyword === 'required'
                    ? self::property([...$path, $name]).': required: The property '.$name.' is required'
                    : self::property($path).': additionalProperties: The property '.$name.' is not defined';
            }
        } else {
            $message = $formatter->formatErrorMessage($error);
            $schema = $error->schema()->info()->data();
            if ($keyword === 'enum' && \is_object($schema) && isset($schema->enum)) {
                $message .= ' '.json_encode($schema->enum, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            }
            $lines[] = self::property($path).': '.$keyword.': '.$message;
        }

        return $lines;
    }

    /** @return list<string> */
    private static function names(ValidationError $error, string $argument): array
    {
        $names = $error->args()[$argument] ?? null;
        Assert::assertIsArray($names);
        $strings = [];
        foreach ($names as $name) {
            Assert::assertIsString($name);
            $strings[] = $name;
        }

        return $strings;
    }

    /**
     * The path as justinrainbow/json-schema writes it: a numeric key in brackets, any other key
     * after a dot (JsonSchema\Constraints\BaseConstraint::convertJsonPointerIntoPropertyPath()).
     *
     * @param list<int|string> $path
     */
    private static function property(array $path): string
    {
        $property = trim(implode('', array_map(static fn ($key): string => is_numeric($key) ? '['.(int) $key.']' : '.'.$key, $path)), '.');

        return $property === '' ? '(root)' : $property;
    }

    /**
     * A keyword parser beside `type`: a float fails a type that allows an integer and no other
     * number. Draft-06 reads 3.0 as an integer, draft-04 does not.
     */
    private static function integerVocabulary(): Vocabulary
    {
        $parser = new class ('type') extends KeywordParser {
            public function type(): string
            {
                return self::TYPE_BEFORE;
            }

            public function parse(SchemaInfo $info, SchemaParser $parser, object $shared): ?Keyword
            {
                $schema = $info->data();
                if (!\is_object($schema) || !$this->keywordExists($schema)) {
                    return null;
                }
                $types = array_values((array) $this->keywordValue($schema));
                if (!\in_array('integer', $types, true) || \in_array('number', $types, true)) {
                    return null;
                }

                return new class (implode(', ', array_filter($types, '\is_string'))) implements Keyword {
                    use ErrorTrait;

                    private string $expected;

                    public function __construct(string $expected)
                    {
                        $this->expected = $expected;
                    }

                    public function validate(ValidationContext $context, Schema $schema): ?ValidationError
                    {
                        if (!\is_float($context->currentData())) {
                            return null;
                        }

                        return $this->error($schema, $context, 'type', 'The data ({type}) must match the type: {expected}', ['type' => 'number', 'expected' => $this->expected]);
                    }
                };
            }
        };

        return new class ([$parser]) extends Vocabulary {
        };
    }
}
