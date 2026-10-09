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
 * Finds where src/ reads an array that a writer method wrote ({@see WRITER_METHODS}). The taint
 * follows variables, properties, elements, `foreach`, callbacks and own methods, flow-insensitive.
 * A read is a key, a keyed destructuring or a key function. Only a writer file can read, and only
 * a writer calls `jsonSerialize()`. No file passes a written array to another object's method or
 * to a class that is not a writer or its listed holder: the analysis does not leave the file.
 */
final class WrittenArrayReads
{
    private const WRITER_METHODS = ['toarray', 'jsonserialize', 'findingrows', 'toexplainarray'];
    /** The functions whose result holds the rows or the keys of their array argument. */
    private const PASS_ON = ['array_values', 'array_slice', 'array_splice', 'array_merge', 'array_replace', 'array_filter', 'array_reverse', 'array_unique', 'array_pad', 'array_chunk', 'reset', 'end', 'current', 'next', 'prev', 'iterator_to_array', 'array_pop', 'array_shift', 'array_merge_recursive', 'array_replace_recursive', 'array_combine'];
    private const KEY_FUNCTIONS = ['array_key_exists', 'key_exists', 'array_column', 'array_intersect_key', 'array_diff_key', 'array_keys', 'extract', 'array_key_first', 'array_key_last'];
    /** The callback argument of each function and the callback parameters that get its array's rows. */
    private const CALLBACKS = [
        'array_map' => [0, [0]],
        'array_filter' => [1, [0]],
        'array_walk' => [1, [0]],
        'array_reduce' => [1, [1]],
        'usort' => [1, [0, 1]],
        'uasort' => [1, [0, 1]],
    ];
    /** A bound on the passes over one class: each pass taints at least one more name or position. */
    private const PASSES = 50;

    /** @var array<string, true> the tainted variables and `$this` properties of the method in scope, `$name` or `->name` */
    private array $tainted = [];
    /** @var array<string, array<array-key, true>> per own method, the tainted parameter positions */
    private array $params = [];
    /** @var array<string, true> the own methods that return a written array */
    private array $returns = [];
    /** @var array<string, true> the `$this` properties that hold a written array */
    private array $properties = [];
    /** Whether a pass added a tainted parameter, return or property, which the next pass reads. */
    private bool $changed = false;
    private string $class = '';
    /** @var array<string, list<string>> */
    private array $holders = [];

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
            $scan = new self();
            $scan->class = $name;
            $scan->holders = $holders;
            $violations = array_merge($violations, $scan->inClass($class, $isWriter($name), $isWriter));
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
        $pass = 0;
        do {
            if (++$pass > self::PASSES) {
                throw new \LogicException('the taint of '.$this->class.' does not settle');
            }
            $this->changed = false;
            foreach ($methods as $name => $method) {
                $this->taintMethod($name, $method);
            }
        } while ($this->changed);
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
        $returned = $node instanceof Node\Stmt\Return_ && !self::inClosure($node) ? $node->expr : null;
        $returned = $node instanceof Expr\Yield_ || $node instanceof Expr\YieldFrom ? ($node instanceof Expr\Yield_ ? $node->value : $node->expr) : $returned;
        if ($returned !== null && $this->isTainted($returned) && !isset($this->returns[$method])) {
            $this->returns[$method] = true;
            $this->changed = true;
        }
        if ($node instanceof Expr\FuncCall && $node->name instanceof Name) {
            $this->spreadToCallback($node->name->toLowerString(), $node->getArgs());
            $args = $node->getArgs();
            if ($node->name->toLowerString() === 'array_push' && isset($args[0]) && $this->anyTainted(\array_slice($args, 1))) {
                $this->taint($args[0]->value);
            }
        }
        $own = $this->ownMethod($node);
        if ($own !== null && ($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall || $node instanceof Expr\New_)) {
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
        $own = $callback === null ? null : $this->ownCallable($callback);
        if ($tainted && $own !== null) {
            foreach ($positions as $position) {
                if (!isset($this->params[$own][$position])) {
                    $this->params[$own][$position] = true;
                    $this->changed = true;
                }
            }
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
        $parent = $node->getAttribute('parent');
        $inner = $parent instanceof Expr\ArrayDimFetch && $parent->var === $node;
        if (!$mayRead && $node instanceof Expr\ArrayDimFetch && !$inner && !self::isWritten($node) && $this->isTainted($node->var)) {
            return 'reads a key of a written array';
        }
        if (!$mayRead && $node instanceof Expr\FuncCall && $node->name instanceof Name && \in_array($node->name->toLowerString(), self::KEY_FUNCTIONS, true) && $this->anyTainted($node->getArgs())) {
            return 'passes a written array to '.$node->name->toString().'()';
        }
        if (!$mayRead && $node instanceof Expr\Assign && ($node->var instanceof Expr\List_ || $node->var instanceof Expr\Array_) && $this->isTainted($node->expr)) {
            return 'destructures a written array';
        }
        if (!$mayRead && $node instanceof Node\Stmt\Foreach_ && ($node->valueVar instanceof Expr\List_ || $node->valueVar instanceof Expr\Array_) && $this->isTainted($node->expr)) {
            return 'destructures a written array';
        }
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $this->ownMethod($node) === null && $node->name instanceof Node\Identifier && $this->anyTainted($node->getArgs())) {
            return 'passes a written array to ->'.$node->name->toString().'()';
        }
        if (($node instanceof Expr\StaticCall || $node instanceof Expr\New_) && $node->class instanceof Name && $this->ownMethod($node) === null && !$node->class->isSpecialClassName()) {
            $class = $node->class->toString();
            if ($this->isForeign($class, $isWriter) && $this->anyTainted($node->getArgs())) {
                return 'passes a written array to '.$class;
            }
        }
        if ($node instanceof Expr\FuncCall && $node->name instanceof Name && isset(self::CALLBACKS[$node->name->toLowerString()])) {
            $args = $node->getArgs();
            $callback = $args[self::CALLBACKS[$node->name->toLowerString()][0]]->value ?? null;
            $class = $callback instanceof Expr\Array_ && $this->ownCallable($callback) === null ? self::callableClass($callback) : null;
            if ($class !== null && $this->isForeign($class, $isWriter) && $this->anyTainted($args)) {
                return 'passes a written array to '.$class;
            }
        }

        return null;
    }

    /** @param callable(string): bool $isWriter */
    private function isForeign(string $class, callable $isWriter): bool
    {
        return strncmp($class, 'Lockrot\\', 8) === 0 && !$isWriter($class) && !\in_array($this->class, $this->holders[$class] ?? [], true);
    }

    /** The own method of a callable array: `[$this, 'm']`, `[self::class, 'm']` or the own class name. */
    private function ownCallable(Expr $callback): ?string
    {
        $class = $callback instanceof Expr\Array_ ? self::callableClass($callback) : null;
        $method = $callback instanceof Expr\Array_ ? ($callback->items[1]->value ?? null) : null;
        if ($class === null || !$method instanceof Node\Scalar\String_) {
            return null;
        }

        return \in_array(strtolower($class), ['$this', 'self', 'static'], true) || $class === $this->class ? strtolower($method->value) : null;
    }

    /** The class of a two-item callable array, `$this` for the object itself. */
    private static function callableClass(Expr\Array_ $callback): ?string
    {
        $target = \count($callback->items) === 2 ? ($callback->items[0]->value ?? null) : null;
        if ($target instanceof Expr\Variable && $target->name === 'this') {
            return '$this';
        }
        if ($target instanceof Expr\ClassConstFetch && $target->class instanceof Name && $target->name instanceof Node\Identifier && $target->name->toLowerString() === 'class') {
            return $target->class->toString();
        }

        return null;
    }

    private static function inClosure(Node $node): bool
    {
        for ($parent = $node->getAttribute('parent'); $parent instanceof Node; $parent = $parent->getAttribute('parent')) {
            if ($parent instanceof Expr\Closure || $parent instanceof Expr\ArrowFunction) {
                return true;
            }
            if ($parent instanceof ClassMethod) {
                return false;
            }
        }

        return false;
    }

    /** Whether the key is the target of an assignment or an unset. Both write the array and do not read it. */
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
     * Whether the value of $expr is a written array or a part of one. These calls return one:
     *
     * - a writer method, or an own method that returns a written array
     * - a function of {@see PASS_ON} with a written array argument
     * - `array_map()` whose callback returns a written array
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
        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Name && $expr->name->toLowerString() === 'array_map') {
            $callback = $expr->getArgs()[0]->value ?? null;

            $own = $callback === null ? null : $this->ownCallable($callback);
            if ($own !== null) {
                return isset($this->returns[$own]);
            }

            return $callback instanceof Expr\ArrowFunction ? $this->isTainted($callback->expr) : ($callback instanceof Expr\Closure && $this->returnsTainted($callback));
        }

        return false;
    }

    private function returnsTainted(Expr\Closure $closure): bool
    {
        foreach ((new NodeFinder())->findInstanceOf($closure->stmts, Node\Stmt\Return_::class) as $return) {
            if ($return->expr !== null && $this->isTainted($return->expr)) {
                return true;
            }
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

    /** A written array stored into an element taints the whole array that holds it. */
    private function taint(?Node $target): void
    {
        while ($target instanceof Expr\ArrayDimFetch) {
            $target = $target->var;
        }
        $key = $target === null ? null : self::key($target);
        if ($key !== null && !isset($this->tainted[$key])) {
            $this->tainted[$key] = true;
            if (strncmp($key, '->', 2) === 0 && !isset($this->properties[substr($key, 2)])) {
                $this->properties[substr($key, 2)] = true;
                $this->changed = true;
            }
        }
    }

    /**
     * The lower-case name of the method that an own call targets. Own calls are `$this->m()`,
     * `self::m()`, `static::m()`, a call by the class's own name and a `new` of the class itself.
     */
    private function ownMethod(Node $node): ?string
    {
        if ($node instanceof Expr\New_ && $node->class instanceof Name && (\in_array($node->class->toLowerString(), ['self', 'static'], true) || $node->class->toString() === $this->class)) {
            return '__construct';
        }
        if ($node instanceof Expr\MethodCall && $node->var instanceof Expr\Variable && $node->var->name === 'this' && $node->name instanceof Node\Identifier) {
            return $node->name->toLowerString();
        }
        if ($node instanceof Expr\StaticCall && $node->class instanceof Name && (\in_array($node->class->toLowerString(), ['self', 'static'], true) || $node->class->toString() === $this->class) && $node->name instanceof Node\Identifier) {
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
        if ($node instanceof Expr\StaticPropertyFetch && $node->name instanceof Node\VarLikeIdentifier) {
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
