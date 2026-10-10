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
 * Finds where src/ uses the array of a writer method ({@see WRITER_METHOD}). Outside a writer class,
 * the array goes only to a writer method's return or to an argument of a writer or a listed holder.
 * On the way it can pass the nodes of {@see through()}. A writer class gives the array, a key or a
 * copy of it and one variable that holds them only to a writer. It returns or yields them only from
 * a writer method or a private method.
 */
final class WrittenArrayReads
{
    private const WRITER_METHOD = '/^(to\w*array|jsonserialize|findingrows)$/i';
    private const OUTSIDE = 'uses a written array outside a return, an array item or a writer';
    /** The functions whose result holds rows or keys of their array argument. */
    private const COPIES = ['array_values', 'array_filter', 'array_slice', 'array_reverse', 'array_column', 'array_replace', 'array_unique', 'iterator_to_array', 'reset', 'end', 'current'];

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

    /** A call of a writer method, or a callable array of one. */
    private static function isSource(Node $node): bool
    {
        if ($node instanceof Expr\Array_ && \count($node->items) === 2 && $node->items[0] !== null && $node->items[1] !== null && $node->items[0]->key === null && $node->items[1]->key === null) {
            $target = $node->items[0]->value;
            $method = $node->items[1]->value;

            return !$target instanceof Node\Scalar && !$target instanceof Expr\Array_ && $method instanceof Node\Scalar\String_ && preg_match(self::WRITER_METHOD, $method->value) === 1;
        }

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

    /** What is wrong where a writer class gives the array of a writer method to a class that is not a writer, or null. */
    private function passedOn(Node $call): ?string
    {
        [$parent, $child] = self::onward(...self::position($call));
        $uses = [$parent];
        $names = self::assigned($parent, $child);
        if ($names !== []) {
            $uses = [];
            $method = $parent === null ? null : self::method($parent);
            foreach ((new NodeFinder())->findInstanceOf($method === null ? [] : ($method->stmts ?? []), Expr\Variable::class) as $variable) {
                if (\in_array($variable->name, $names, true) && !self::isShadowed($variable)) {
                    $uses[] = self::onward(...self::position($variable))[0];
                }
            }
        }
        foreach ($uses as $use) {
            $method = $use instanceof Node\Stmt\Return_ || $use instanceof Expr\Yield_ || $use instanceof Expr\YieldFrom ? self::method($use) : null;
            if ($method !== null && !$method->isPrivate() && preg_match(self::WRITER_METHOD, $method->name->toString()) !== 1) {
                return 'returns a written array from a method that is not a writer';
            }
            $target = $use instanceof Node\Arg ? $this->target($use) : null;
            if ($target !== null && $target !== '') {
                return 'passes a written array to '.$target;
            }
        }

        return null;
    }

    /**
     * The position past each key read and each copy of {@see COPIES}: a key or a copy of a written
     * array is written too.
     *
     * @return array{Node|null, Node}
     */
    private static function onward(?Node $parent, Node $child): array
    {
        while (true) {
            $call = $parent instanceof Node\Arg ? $parent->getAttribute('parent') : null;
            if ($parent instanceof Expr\ArrayDimFetch && $parent->var === $child) {
                [$parent, $child] = self::position($parent);
            } elseif ($call instanceof Expr\FuncCall && $call->name instanceof Name && \in_array($call->name->toLowerString(), self::COPIES, true)) {
                [$parent, $child] = self::position($call);
            } else {
                return [$parent, $child];
            }
        }
    }

    /**
     * The variables that an assignment of $child puts the array into: the target, the root of a
     * key target, a union with `+=` or the variables of a destructuring.
     *
     * @return list<string>
     */
    private static function assigned(?Node $parent, Node $child): array
    {
        if (!($parent instanceof Expr\Assign || $parent instanceof Expr\AssignOp\Plus) || $parent->expr !== $child) {
            return [];
        }
        $targets = $parent->var instanceof Expr\List_ || $parent->var instanceof Expr\Array_ ? array_map(static fn (?Node\ArrayItem $item): ?Expr => $item === null ? null : $item->value, $parent->var->items) : [$parent->var];
        $names = [];
        foreach ($targets as $target) {
            while ($target instanceof Expr\ArrayDimFetch) {
                $target = $target->var;
            }
            if ($target instanceof Expr\Variable && \is_string($target->name)) {
                $names[] = $target->name;
            }
        }

        return $names;
    }

    /** Whether a closure or an arrow function inside the method binds the name of $variable as a parameter. */
    private static function isShadowed(Expr\Variable $variable): bool
    {
        $function = self::function($variable);
        if (!$function instanceof Expr\Closure && !$function instanceof Expr\ArrowFunction) {
            return false;
        }
        foreach ($function->getParams() as $param) {
            if ($param->var instanceof Expr\Variable && $param->var->name === $variable->name) {
                return true;
            }
        }

        return false;
    }

    /**
     * The node that takes the value of $expr past the nodes of {@see through()}.
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

    /**
     * The node whose position counts for $child inside $parent, or null when $parent takes the value.
     * A ternary branch, a side of `??` or `+` and an array item keep the position of the value. So
     * do an argument of `array_merge()` and an `array_map()` callback with its body.
     */
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

            if ($call instanceof Expr\FuncCall && $child instanceof Expr\Array_ && self::mappedBy($child) === $call) {
                return $call;
            }

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
     * The class or method that an argument goes to. It is an empty string for a writer or a listed
     * holder, and null for an own call or a function.
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
