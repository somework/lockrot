<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use PhpParser\Node;
use PhpParser\Node\Arg;
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
 * Finds the places where src/ reads an array that lockrot wrote: the result of a `toArray()`,
 * `jsonSerialize()` or `findingRows()` call. The analysis follows the result through variables,
 * properties, `foreach`, callbacks and the class's own methods, and does not leave the file.
 *
 * A read is a key or an index (`$row['id']`), a keyed destructuring, or a key function such as
 * `array_column()`. A reader is allowed only in a writer file. In every file, a written array must
 * not go to a static call or a `new` of a class that is not a writer, because the analysis cannot
 * follow it there. Outside a writer, no code calls `jsonSerialize()`: the encoder walks the tree.
 */
final class WrittenArrayReads
{
    private const WRITER_METHODS = ['toarray', 'jsonserialize', 'findingrows'];
    /** The functions whose result holds the rows or the keys of their array argument. */
    private const PASS_ON = ['array_values', 'array_slice', 'array_splice', 'array_merge', 'array_replace', 'array_filter', 'array_reverse', 'array_unique', 'array_pad', 'array_chunk', 'reset', 'end', 'current', 'next', 'prev', 'iterator_to_array', 'array_pop', 'array_shift', 'compact'];
    private const KEY_FUNCTIONS = ['array_key_exists', 'key_exists', 'array_column', 'array_intersect_key', 'array_diff_key', 'array_keys', 'extract'];
    /** The callback argument of each function and the callback parameters that get its array's rows. */
    private const CALLBACKS = [
        'array_map' => [0, [0]],
        'array_filter' => [1, [0]],
        'array_walk' => [1, [0]],
        'array_reduce' => [1, [1]],
        'usort' => [1, [0, 1]],
        'uasort' => [1, [0, 1]],
    ];
    /** A bound on the passes over one class: each pass taints at least one more name. */
    private const PASSES = 50;

    /** @var array<string, true> the tainted variables and `$this` properties of the method in scope, `$name` or `->name` */
    private array $tainted = [];
    /** @var array<string, array<array-key, true>> per own method, the tainted parameter positions */
    private array $params = [];
    /** @var array<string, true> the own methods that return a written array */
    private array $returns = [];
    /** @var array<string, true> the `$this` properties that hold a written array */
    private array $properties = [];
    private bool $changed = false;

    /**
     * @param callable(string): bool $isWriter whether a fully qualified class name is a writer
     *
     * @return list<string> one line per violation: the line number and what the code does
     */
    public static function inSource(string $source, callable $isWriter): array
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
        if ($statements === null) {
            throw new \UnexpectedValueException('the source does not parse');
        }
        $statements = (new NodeTraverser(new NameResolver(), new ParentConnectingVisitor()))->traverse($statements);
        $violations = [];
        foreach ((new NodeFinder())->findInstanceOf($statements, ClassLike::class) as $class) {
            $name = $class->namespacedName === null ? '' : $class->namespacedName->toString();
            $violations = array_merge($violations, (new self())->inClass($class, $isWriter($name), $isWriter));
        }

        return $violations;
    }

    /**
     * @param callable(string): bool $isWriter
     *
     * @return list<string>
     */
    private function inClass(ClassLike $class, bool $mayRead, callable $isWriter): array
    {
        $methods = [];
        foreach ($class->getMethods() as $method) {
            $methods[$method->name->toLowerString()] = $method;
        }
        for ($pass = 0; $pass < self::PASSES; ++$pass) {
            $this->changed = false;
            foreach ($methods as $name => $method) {
                $this->taintMethod($name, $method);
            }
            if (!$this->changed) {
                break;
            }
        }
        $violations = [];
        foreach ($methods as $name => $method) {
            $this->taintMethod($name, $method);
            foreach ($this->nodes($method) as $node) {
                $violation = $this->violation($node, $mayRead, $isWriter);
                if ($violation !== null) {
                    $violations[] = $node->getStartLine().': '.$violation;
                }
            }
        }

        return $violations;
    }

    private function scope(string $name, ClassMethod $method): void
    {
        $this->tainted = [];
        foreach ($method->params as $at => $param) {
            if (isset($this->params[$name][$at]) && $param->var instanceof Expr\Variable && \is_string($param->var->name)) {
                $this->tainted['$'.$param->var->name] = true;
            }
        }
        foreach ($this->properties as $property => $true) {
            $this->tainted['->'.$property] = $true;
        }
    }

    private function taintMethod(string $name, ClassMethod $method): void
    {
        $this->scope($name, $method);
        do {
            $before = \count($this->tainted);
            foreach ($this->nodes($method) as $node) {
                $this->spread($node, $name);
            }
        } while (\count($this->tainted) > $before);
    }

    private function spread(Node $node, string $method): void
    {
        if (($node instanceof Expr\Assign || $node instanceof Expr\AssignRef || $node instanceof Expr\AssignOp) && $this->isTainted($node->expr)) {
            $this->taint($node->var);
        }
        if ($node instanceof Node\Stmt\Foreach_ && $this->isTainted($node->expr)) {
            $this->taint($node->valueVar);
        }
        if ($node instanceof Node\Stmt\Return_ && $node->expr !== null && $this->isTainted($node->expr) && !isset($this->returns[$method])) {
            $this->returns[$method] = true;
            $this->changed = true;
        }
        if ($node instanceof Expr\FuncCall && $node->name instanceof Name) {
            $this->spreadToCallback($node->name->toLowerString(), $node->getArgs());
        }
        $own = $this->ownMethod($node);
        if ($own !== null && ($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall)) {
            foreach ($node->getArgs() as $at => $arg) {
                if ($this->isTainted($arg->value) && !isset($this->params[$own][$at])) {
                    $this->params[$own][$at] = true;
                    $this->changed = true;
                }
            }
        }
    }

    /** @param array<Arg> $args */
    private function spreadToCallback(string $function, array $args): void
    {
        if (!isset(self::CALLBACKS[$function])) {
            return;
        }
        [$at, $positions] = self::CALLBACKS[$function];
        $callback = $args[$at]->value ?? null;
        $tainted = false;
        foreach ($args as $i => $arg) {
            $tainted = $tainted || ($i !== $at && $this->isTainted($arg->value));
        }
        if (!$tainted || !($callback instanceof Expr\Closure || $callback instanceof Expr\ArrowFunction)) {
            return;
        }
        foreach ($positions as $position) {
            if (isset($callback->params[$position])) {
                $this->taint($callback->params[$position]->var);
            }
        }
    }

    /** @param callable(string): bool $isWriter */
    private function violation(Node $node, bool $mayRead, callable $isWriter): ?string
    {
        if (!$mayRead && ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall || $node instanceof Expr\StaticCall) && $node->name instanceof Node\Identifier && $node->name->toLowerString() === 'jsonserialize') {
            return 'calls jsonSerialize(): only the encoder walks the tree';
        }
        if (!$mayRead && $node instanceof Expr\ArrayDimFetch && !self::isWritten($node) && $this->isTainted($node->var)) {
            return 'reads a key of a written array';
        }
        if (!$mayRead && $node instanceof Expr\FuncCall && $node->name instanceof Name && \in_array($node->name->toLowerString(), self::KEY_FUNCTIONS, true) && $this->anyTainted($node->getArgs())) {
            return 'passes a written array to '.$node->name->toString().'()';
        }
        if (!$mayRead && $node instanceof Expr\Assign && ($node->var instanceof Expr\List_ || $node->var instanceof Expr\Array_) && $this->isTainted($node->expr)) {
            return 'destructures a written array';
        }
        if (($node instanceof Expr\StaticCall || $node instanceof Expr\New_) && $node->class instanceof Name && $this->ownMethod($node) === null && !$node->class->isSpecialClassName()) {
            $class = $node->class->toString();
            if (strncmp($class, 'Lockrot\\', 8) === 0 && !$isWriter($class) && $this->anyTainted($node->getArgs())) {
                return 'passes a written array to '.$class;
            }
        }

        return null;
    }

    /** Whether the key is the target of an assignment or an unset, which writes the array, not reads it. */
    private static function isWritten(Expr\ArrayDimFetch $node): bool
    {
        $child = $node;
        $parent = $node->getAttribute('parent');
        while ($parent instanceof Expr\ArrayDimFetch && $parent->var === $child) {
            $child = $parent;
            $parent = $parent->getAttribute('parent');
        }
        if ($parent instanceof Expr\Assign || $parent instanceof Expr\AssignOp || $parent instanceof Expr\AssignRef) {
            return $parent->var === $child;
        }

        return $parent instanceof Node\Stmt\Unset_;
    }

    /** @param array<Arg> $args */
    private function anyTainted(array $args): bool
    {
        foreach ($args as $arg) {
            if ($this->isTainted($arg->value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the value of $expr is a written array or a part of one. A call returns one only when
     * it is a writer call, an own method that returns one, or a function that passes its array on.
     */
    private function isTainted(Expr $expr): bool
    {
        if ($this->isSource($expr)) {
            return true;
        }
        if ($expr instanceof Expr\ArrayDimFetch || $expr instanceof Expr\Cast\Array_) {
            return $expr instanceof Expr\ArrayDimFetch ? $this->isTainted($expr->var) : $this->isTainted($expr->expr);
        }
        if ($expr instanceof Expr\Assign || $expr instanceof Expr\AssignRef) {
            return $this->isTainted($expr->expr);
        }
        if ($expr instanceof Expr\Ternary) {
            return ($expr->if !== null && $this->isTainted($expr->if)) || $this->isTainted($expr->else) || ($expr->if === null && $this->isTainted($expr->cond));
        }
        if ($expr instanceof Expr\BinaryOp\Coalesce || $expr instanceof Expr\BinaryOp\Plus) {
            return $this->isTainted($expr->left) || $this->isTainted($expr->right);
        }
        if ($expr instanceof Expr\Match_) {
            foreach ($expr->arms as $arm) {
                if ($this->isTainted($arm->body)) {
                    return true;
                }
            }

            return false;
        }
        if ($expr instanceof Expr\Array_) {
            foreach ($expr->items as $item) {
                if ($item !== null && $this->isTainted($item->value)) {
                    return true;
                }
            }

            return false;
        }
        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Name && \in_array($expr->name->toLowerString(), self::PASS_ON, true)) {
            return $this->anyTainted($expr->getArgs());
        }

        return false;
    }

    private function isSource(Node $node): bool
    {
        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall || $node instanceof Expr\StaticCall) {
            $name = $node->name instanceof Node\Identifier ? $node->name->toLowerString() : null;
            $own = $this->ownMethod($node);
            if (\in_array($name, self::WRITER_METHODS, true) || ($own !== null && isset($this->returns[$own]))) {
                return true;
            }
        }
        $key = self::key($node);

        return $key !== null && isset($this->tainted[$key]);
    }

    private function taint(?Node $target): void
    {
        $key = $target === null ? null : self::key($target);
        if ($key !== null && !isset($this->tainted[$key])) {
            $this->tainted[$key] = true;
            if (strncmp($key, '->', 2) === 0) {
                $this->properties[substr($key, 2)] = true;
            }
            $this->changed = true;
        }
    }

    /** The own method that a `$this->m()`, `self::m()` or `static::m()` call names, lower case. */
    private function ownMethod(Node $node): ?string
    {
        if ($node instanceof Expr\MethodCall && $node->var instanceof Expr\Variable && $node->var->name === 'this' && $node->name instanceof Node\Identifier) {
            return $node->name->toLowerString();
        }
        if ($node instanceof Expr\StaticCall && $node->class instanceof Name && \in_array($node->class->toLowerString(), ['self', 'static'], true) && $node->name instanceof Node\Identifier) {
            return $node->name->toLowerString();
        }

        return null;
    }

    private static function key(Node $node): ?string
    {
        if ($node instanceof Expr\Variable && \is_string($node->name)) {
            return '$'.$node->name;
        }
        if ($node instanceof Expr\PropertyFetch && $node->var instanceof Expr\Variable && $node->var->name === 'this' && $node->name instanceof Node\Identifier) {
            return '->'.$node->name->toString();
        }

        return null;
    }

    /** @return array<Node> every node of the method body, the bodies of its closures included */
    private function nodes(ClassMethod $method): array
    {
        return (new NodeFinder())->find($method->stmts ?? [], static fn (Node $node): bool => true);
    }
}
