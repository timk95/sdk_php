<?php
namespace bunq\Model\Core;

use bunq\Exception\BunqException;
use bunq\Http\BunqResponse;
use bunq\Http\BunqResponseRaw;
use bunq\Http\Pagination;
use bunq\Util\ModelUtil;
use JsonSerializable;
use ReflectionClass;
use ReflectionProperty;

/**
 * Base class for all endpoints, responsible for parsing json received from the server.
 */
abstract class BunqModel implements JsonSerializable
{
    /**
     * Error constants.
     */
    const ERROR_PROPERTY_DOES_NOT_EXIST = 'Property "%s" does not exist in "%s"' . PHP_EOL;
    const ERROR_UNEXPECTED_RESULT = 'Unexpected number of results "%d", expected "1".';

    /**
     * Field constants.
     */
    const FIELD_RESPONSE = 'Response';
    const FIELD_PAGINATION = 'Pagination';
    const FIELD_ID = 'Id';
    const FIELD_UUID = 'Uuid';

    /**
     * Regex constants.
     */
    const REGEX_DOC_BLOCK_VARIABLE = '/@var\s(\w+)(\[\])?/';
    const REGEX_EXPECTED_ONE = 1;
    const REGEX_MATCH_RESULT_TYPE = 1;
    const REGEX_MATCH_RESULT_IS_ARRAY = 2;

    /**
     * Index of the very first item in an array.
     */
    const INDEX_FIRST = 0;

    /**
     * Type constants.
     */
    const SCALAR_TYPE_STRING = 'string';
    const SCALAR_TYPE_BOOL = 'bool';
    const SCALAR_TYPE_INT = 'int';
    const SCALAR_TYPE_FLOAT = 'float';
    /**
     * @var string[]
     */
    protected static $fieldNameOverrideMap = [];
    /**
     * Set of the PHP scalar types. Mimicking a constant, and therefore should be used with self::.
     *
     * @var bool[]
     */
    private static $scalarTypes = [
        self::SCALAR_TYPE_STRING => true,
        self::SCALAR_TYPE_BOOL => true,
        self::SCALAR_TYPE_INT => true,
        self::SCALAR_TYPE_FLOAT => true,
    ];

    /**
     * @param BunqResponseRaw $responseRaw
     * @param string $wrapper
     *
     * @return BunqResponse
     */
    protected static function fromJsonList(
        BunqResponseRaw $responseRaw,
        string $wrapper = null
    ): BunqResponse {
        $json = $responseRaw->getBodyString();
        $responseArray = ModelUtil::deserializeResponseArray($json);
        $response = $responseArray[self::FIELD_RESPONSE];
        $value = static::createListFromResponseArray($response, $wrapper);
        $pagination = Pagination::restore($responseArray[self::FIELD_PAGINATION]);

        return new BunqResponse($value, $responseRaw->getHeaders(), $pagination);
    }

    /**
     * @param string $json
     *
     * @return BunqModel
     */
    public static function fromJsonToModel(string $json): BunqModel
    {
        $responseArray = ModelUtil::deserializeResponseArray($json);

        return static::createFromResponseArray($responseArray);
    }

    /**
     * @param mixed[] $responseArray
     * @param string $wrapper
     *
     * @return BunqModel[]
     */
    protected static function createListFromResponseArray(
        array $responseArray,
        string $wrapper = null
    ): array {
        $list = [];

        foreach ($responseArray as $className => $element) {
            $list[] = static::createFromResponseArray($element, $wrapper);
        }

        return $list;
    }

    /**
     * @param mixed[] $responseArray
     * @param string $wrapper
     *
     * @return BunqModel|null
     */
    protected static function createFromResponseArray(array $responseArray, string $wrapper = null)
    {
        if (is_string($wrapper)) {
            $responseArray = $responseArray[$wrapper];
        }

        if (is_null($responseArray)) {
            return null;
        } else {
            return self::createInstanceFromResponseArray($responseArray);
        }
    }

    /**
     * @param mixed[] $responseArray
     *
     * @return BunqModel
     */
    private static function createInstanceFromResponseArray(array $responseArray): BunqModel
    {
        $classDefinition = new \ReflectionClass(static::class);
        /** @var BunqModel $instance */
        $instance = $classDefinition->newInstanceWithoutConstructor();

        foreach ($responseArray as $fieldNameRaw => $contents) {
            $fieldName = static::determineResponseFieldName($fieldNameRaw);

            if ($classDefinition->hasProperty($fieldName)) {
                $property = $classDefinition->getProperty($fieldName);
                $instance->{$fieldName} = static::determineFieldContents($property, $contents);
            }
        }

        return $instance;
    }

    /**
     * @param string $fieldNameRaw
     *
     * @return string
     */
    private static function determineResponseFieldName(string $fieldNameRaw): string
    {
        $fieldNameOverrideMapFlipped = array_flip(static::$fieldNameOverrideMap);

        if (isset($fieldNameOverrideMapFlipped[$fieldNameRaw])) {
            $fieldNameRaw = $fieldNameOverrideMapFlipped[$fieldNameRaw];
        }

        return ModelUtil::snakeCaseToCamelCase($fieldNameRaw);
    }

    /**
     * @param ReflectionProperty $property
     * @param mixed|mixed[] $contents
     *
     * @return BunqModel|BunqModel[]|mixed
     */
    private static function determineFieldContents(ReflectionProperty $property, $contents)
    {
        $docComment = $property->getDocComment();

        if (preg_match(self::REGEX_DOC_BLOCK_VARIABLE, $docComment, $matches) === self::REGEX_EXPECTED_ONE) {
            $fieldType = $matches[self::REGEX_MATCH_RESULT_TYPE];

            if (is_null($contents) || static::isTypeScalar($fieldType)) {
                return $contents;
            } elseif (isset($matches[self::REGEX_MATCH_RESULT_IS_ARRAY])) {
                /** @var BunqModel $modelClassNameQualified */
                $modelClassNameQualified = ModelUtil::determineModelClassNameQualified($fieldType);

                return $modelClassNameQualified::createListFromResponseArray($contents);
            } else {
                /** @var BunqModel $modelClassNameQualified */
                $modelClassNameQualified = ModelUtil::determineModelClassNameQualified($fieldType);

                return $modelClassNameQualified::createFromResponseArray($contents);
            }
        } else {
            return $contents;
        }
    }

    /**
     * @param string $type
     *
     * @return bool
     */
    private static function isTypeScalar(string $type): bool
    {
        return isset(self::$scalarTypes[$type]);
    }

    /**
     * @param BunqResponseRaw $responseRaw
     *
     * @return BunqResponse
     */
    protected static function classFromJson(BunqResponseRaw $responseRaw): BunqResponse
    {
        $json = $responseRaw->getBodyString();
        $response = ModelUtil::deserializeResponseArray($json)[self::FIELD_RESPONSE];
        $formattedResponseArray = ModelUtil::formatResponseArray($response);
        $value = static::createFromResponseArray($formattedResponseArray);

        return new BunqResponse($value, $responseRaw->getHeaders());
    }

    /**
     * @param BunqResponseRaw $responseRaw
     *
     * @return BunqResponse
     */
    protected static function processForId(BunqResponseRaw $responseRaw): BunqResponse
    {
        $id = Id::fromJson($responseRaw, self::FIELD_ID)->getValue();

        return new BunqResponse($id->getId(), $responseRaw->getHeaders());
    }

    /**
     * @param BunqResponseRaw $responseRaw
     * @param string|null $wrapper
     *
     * @return BunqResponse
     * @throws BunqException when the result is not expected.
     */
    protected static function fromJson(BunqResponseRaw $responseRaw, string $wrapper = null): BunqResponse
    {
        $json = $responseRaw->getBodyString();
        $responseArray = ModelUtil::deserializeResponseArray($json);
        $response = $responseArray[self::FIELD_RESPONSE];
        $value = static::createListFromResponseArray($response, $wrapper);

        return new BunqResponse($value[self::INDEX_FIRST], $responseRaw->getHeaders());
    }

    /**
     * @param BunqResponseRaw $responseRaw
     *
     * @return BunqResponse
     */
    protected static function processForUuid(BunqResponseRaw $responseRaw): BunqResponse
    {
        $uuid = Uuid::fromJson($responseRaw, self::FIELD_UUID)->getValue();

        return new BunqResponse($uuid->getUuid(), $responseRaw->getHeaders());
    }

    /**
     * @return mixed[]
     */
    public function jsonSerialize(): array
    {
        $array = [];

        foreach ($this->getNonStaticProperties() as $property) {
            $fieldName = static::determineRequestFieldName($property);
            $array[$fieldName] = $this->{$property->getName()};
        }

        return $array;
    }

    /**
     * @return ReflectionProperty[]
     */
    private function getNonStaticProperties(): array
    {
        $reflectionClass = new ReflectionClass($this);

        return array_diff(
            $reflectionClass->getProperties(ReflectionProperty::IS_PROTECTED),
            $reflectionClass->getProperties(ReflectionProperty::IS_STATIC)
        );
    }

    /**
     * @param ReflectionProperty $property
     *
     * @return string
     */
    private static function determineRequestFieldName(ReflectionProperty $property): string
    {
        $fieldName = ModelUtil::camelCaseToSnakeCase($property->getName());

        if (isset(static::$fieldNameOverrideMap[$fieldName])) {
            return static::$fieldNameOverrideMap[$fieldName];
        } else {
            return $fieldName;
        }
    }
}
