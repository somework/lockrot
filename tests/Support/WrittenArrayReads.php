<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

/**
 * Finds where src/ uses an array that a writer method wrote ({@see WRITER_METHOD}). Outside a
 * writer class, such a call stands only as an array item, as the return value of a writer method,
 * as an argument of `array_merge()` or of a writer or a listed holder, or as the body of an
 * `array_map()` callback. The last three count only when their call stands in one of these
 * positions, and a ternary branch or a side of `??` or `+` counts as the whole expression.
 */
final class WrittenArrayReads
{
    private const WRITER_METHOD = '/^(to\w*array|jsonserialize|findingrows)$/i';
    private const OUTSIDE = 'uses a written array outside a return, an array item or a writer';

    /** @var callable(string): bool */
    private $isWriter;
    private string $class;
    /** @var list<string> */
    private array $holders;

    /**
     * @param callable(string): bool       $isWriter whether a fully qualified class name is a writer
     * @param array<string, list<string>> $holders  per class, the writers that can pass it a written array
     *
     * @return list<string> one line per violation: the line number and what the code does
     */
    public static function inSource(string $source, callable $isWriter, array $holders = []): array
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
        if ($statements === null) {
            throw new \UnexpectedValueException('the source does not parse');
        }
        $statements = (new NodeTraverser(new NameResolver(), new ParentConnectingVisitor()))->traverse($statements);
        $violations = [];
        foreach ((new NodeFinder())->findInstanceOf($statements, ClassLike::class) as $class) {
            $name = $class->namespacedName === null ? '' : $class->namespacedName->toString();
            $scan = new self($isWriter, $name, $holders);
            foreach ($scan->inClass($class) as $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * @param callable(string): bool       $isWriter
     * @param array<string, list<string>> $holders
     */
    private function __construct(callable $isWriter, string $class, array $holders)
    {
        $this->isWriter = $isWriter;
        $this->class = $class;
        $this->holders = [];
        foreach ($holders as $holder => $writers) {
            if (\in_array($class, $writers, true)) {
                $this->holders[] = $holder;
            }
        }
    }

    /** @return list<string> */
    private function inClass(ClassLike $class): array
    {
        $isWriter = ($this->isWriter)($this->class);
        $violations = [];
        foreach ((new NodeFinder())->find($class->getMethods(), static fn (Node $node): bool => self::isSource($node)) as $call) {
            $violation = $isWriter ? $this->passedOn($call) : $this->misplaced($call);
            if ($violation !== null) {
                $violations[] = $call->getStartLine().': '.$violation;
            }
        }

        return $violations;
    }

    private static function isSource(Node $node): bool
    {
        return ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall || $node instanceof Expr\StaticCall)
            && $node->name instanceof Node\Identifier
            && preg_match(self::WRITER_METHOD, $node->name->toString()) === 1;
    }

    /** What is wrong where a class that is not a writer calls a writer method, or null. */
    private function misplaced(Node $call): ?string
    {
        if (($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall || $call instanceof Expr\StaticCall) && $call->name instanceof Node\Identifier && $call->name->toLowerString() === 'jsonserialize') {
            return 'calls jsonSerialize(): only the encoder walks the tree';
        }
        [$parent] = self::position($call);
        if ($parent instanceof Node\Stmt\Return_) {
            $method = self::method($parent);

            return $method !== null && preg_match(self::WRITER_METHOD, $method->name->toString()) === 1 ? null : 'returns a written array from a method that is not a writer';
        }
        $target = $parent instanceof Node\Arg ? $this->target($parent) : null;
        if ($target === null || $target === '') {
            return $target === null ? self::OUTSIDE : null;
        }

        return 'passes a written array to '.$target;
    }

    /** What is wrong where a writer class hands on the array of a writer method, or null. */
    private function passedOn(Node $call): ?string
    {
        [$parent, $child] = self::position($call);
        $uses = [$parent];
        if ($parent instanceof Expr\Assign && $parent->expr === $child && $parent->var instanceof Expr\Variable && \is_string($parent->var->name)) {
            $uses = [];
            $method = self::method($parent);
            foreach ((new NodeFinder())->findInstanceOf($method === null ? [] : ($method->stmts ?? []), Expr\Variable::class) as $variable) {
                if ($variable->name === $parent->var->name && $variable !== $parent->var) {
                    $uses[] = self::position($variable)[0];
                }
            }
        }
        foreach ($uses as $use) {
            $target = $use instanceof Node\Arg ? $this->target($use) : null;
            if ($target !== null && $target !== '') {
                return 'passes a written array to '.$target;
            }
        }

        return null;
    }

    /**
     * The node that takes the value of $expr, past a ternary branch, a side of `??` or `+`, an array item,
     * an argument of `array_merge()` and the body of an `array_map()` callback.
     *
     * @return array{Node|null, Node} the node and its child that holds the value
     */
    private static function position(Node $expr): array
    {
        $child = $expr;
        $parent = $expr->getAttribute('parent');
        while ($parent instanceof Node) {
            $next = self::through($parent, $child);
            if ($next === null) {
                return [$parent, $child];
            }
            $child = $next;
            $parent = $next->getAttribute('parent');
        }

        return [null, $child];
    }

    /** The node whose position counts for $child inside $parent, or null when $parent takes the value. */
    private static function through(Node $parent, Node $child): ?Node
    {
        if ($parent instanceof Expr\Ternary && ($parent->if === $child || $parent->else === $child || ($parent->if === null && $parent->cond === $child))) {
            return $parent;
        }
        if ($parent instanceof Expr\BinaryOp\Coalesce || $parent instanceof Expr\BinaryOp\Plus) {
            return $parent;
        }
        if ($parent instanceof Node\ArrayItem && $parent->value === $child) {
            $array = $parent->getAttribute('parent');

            return $array instanceof Expr\Array_ ? $array : null;
        }
        if ($parent instanceof Node\Arg && !$parent->unpack) {
            $call = $parent->getAttribute('parent');

            return $call instanceof Expr\FuncCall && self::isFunction($call, 'array_merge') ? $call : null;
        }
        $callback = $parent instanceof Expr\ArrowFunction && $parent->expr === $child ? $parent : null;
        if ($parent instanceof Node\Stmt\Return_) {
            $function = self::function($parent);
            $callback = $function instanceof Expr\Closure ? $function : null;
        }

        return $callback === null ? null : self::mappedBy($callback);
    }

    /** The `array_map()` call whose callback $callback is, or null. */
    private static function mappedBy(Expr $callback): ?Expr\FuncCall
    {
        $arg = $callback->getAttribute('parent');
        $call = $arg instanceof Node\Arg ? $arg->getAttribute('parent') : null;

        return $call instanceof Expr\FuncCall && self::isFunction($call, 'array_map') && ($call->getArgs()[0] ?? null) === $arg ? $call : null;
    }

    /**
     * The class or method that an argument goes to: an empty string for a writer or a listed holder,
     * null for an own call or a function.
     */
    private function target(Node\Arg $arg): ?string
    {
        $call = $arg->getAttribute('parent');
        if (($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall) && $call->name instanceof Node\Identifier) {
            return $call->var instanceof Expr\Variable && $call->var->name === 'this' ? null : '->'.$call->name->toString().'()';
        }
        if (!($call instanceof Expr\StaticCall || $call instanceof Expr\New_) || !$call->class instanceof Name) {
            return null;
        }
        $class = $call->class->toString();
        if ($call instanceof Expr\StaticCall && ($call->class->isSpecialClassName() || $class === $this->class)) {
            return null;
        }

        return ($this->isWriter)($class) || \in_array($class, $this->holders, true) ? '' : $class;
    }

    private static function isFunction(Expr\FuncCall $call, string $name): bool
    {
        return $call->name instanceof Name && $call->name->toLowerString() === $name;
    }

    private static function method(Node $node): ?ClassMethod
    {
        $function = self::function($node);

        return $function instanceof ClassMethod ? $function : null;
    }

    /** The innermost method, closure or arrow function around $node. */
    private static function function(Node $node): ?Node\FunctionLike
    {
        for ($parent = $node->getAttribute('parent'); $parent instanceof Node; $parent = $parent->getAttribute('parent')) {
            if ($parent instanceof Node\FunctionLike) {
                return $parent;
            }
        }

        return null;
    }
}
