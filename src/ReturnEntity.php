<?php

declare(strict_types=1);

namespace Ray\MediaQuery;

use phpDocumentor\Reflection\DocBlock\Tags\Return_;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\Types\Array_;
use phpDocumentor\Reflection\Types\ContextFactory;
use phpDocumentor\Reflection\Types\Object_;
use ReflectionMethod;

use function assert;
use function class_exists;
use function substr;

final class ReturnEntity
{
    /** @var class-string  */
    public string|null $type = '';

    public function __construct(ReflectionMethod $method)
    {
        $returnType = $method->getReturnType();
        if ($returnType === null) {
            return;
        }

        $returnTypeClass = $returnType->getName();
        if (class_exists($returnTypeClass)) {
            $this->type = $returnTypeClass;

            return;
        }

        $factory = DocBlockFactory::createInstance();
        $context = (new ContextFactory())->createFromReflector($method);
        $docComment = $method->getDocComment();
        if ($docComment === false) {
            return;
        }

        $docblock = $factory->create($docComment, $context);
        $returns = $docblock->getTagsByName('return');
        if (! isset($returns[0])) {
            return;
        }

        $return = $returns[0];
        assert($return instanceof Return_);
        $type = $return->getType();
        if (! $type instanceof Array_) {
            return;
        }

        $valueType = $type->getValueType();
        if (! $valueType instanceof Object_) {
            return;
        }

        $fqsen = (string) $valueType->getFqsen();

        if (! class_exists($fqsen)) {
            return;
        }

        $this->type = substr($fqsen, 1);
    }
}
