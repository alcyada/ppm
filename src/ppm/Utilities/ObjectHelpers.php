<?php

    declare(strict_types=1);

    namespace ppm\Utilities;

    use ppm\Exceptions\MemberAccessException;
    use ReflectionClass;
    use ReflectionException;
    use ReflectionMethod;
    use ReflectionProperty;
    use Reflector;

    /**
     * Class ObjectHelpers
     * @package ppm\Utilities
     */
    final class ObjectHelpers
    {
        /**
         * @param string $class
         * @param string $name
         * @throws ReflectionException
         * @noinspection PhpUnused
         */
        public static function strictGet(string $class, string $name): void
        {
            $rc = new ReflectionClass($class);
            $hint = self::getSuggestion(array_merge(
                array_filter($rc->getProperties(ReflectionProperty::IS_PUBLIC), function ($p) { return !$p->isStatic(); }),
                self::parseFullDoc($rc, '~^[ \t*]*@property(?:-read)?[ \t]+(?:\S+[ \t]+)??\$(\w+)~m')
            ), $name);
            throw new MemberAccessException("Cannot read an undeclared property $class::\$$name" . ($hint ? ", did you mean \$$hint?" : '.'));
        }

        /**
         * @param string $class
         * @param string $name
         * @throws ReflectionException
         * @noinspection PhpUnused
         */
        public static function strictSet(string $class, string $name): void
        {
            $rc = new ReflectionClass($class);
            $hint = self::getSuggestion(array_merge(
                array_filter($rc->getProperties(ReflectionProperty::IS_PUBLIC), function ($p) { return !$p->isStatic(); }),
                self::parseFullDoc($rc, '~^[ \t*]*@property(?:-write)?[ \t]+(?:\S+[ \t]+)??\$(\w+)~m')
            ), $name);
            throw new MemberAccessException("Cannot write to an undeclared property $class::\$$name" . ($hint ? ", did you mean \$$hint?" : '.'));
        }

        /**
         * @param string $class
         * @param string $method
         * @param array $additionalMethods
         * @throws ReflectionException
         * @noinspection PhpUnused
         */
        public static function strictCall(string $class, string $method, array $additionalMethods = []): void
        {
            $hint = self::getSuggestion(array_merge(
                get_class_methods($class),
                self::parseFullDoc(new ReflectionClass($class), '~^[ \t*]*@method[ \t]+(?:\S+[ \t]+)??(\w+)\(~m'),
                $additionalMethods
            ), $method);

            if (method_exists($class, $method)) { // called parent::$method()
                $class = 'parent';
            }
            throw new MemberAccessException("Call to undefined method $class::$method()" . ($hint ? ", did you mean $hint()?" : '.'));
        }

        /**
         * @param string $class
         * @param string $method
         * @throws ReflectionException
         * @noinspection PhpUnused
         */
        public static function strictStaticCall(string $class, string $method): void
        {
            $hint = self::getSuggestion(
                array_filter((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC), function ($m) { return $m->isStatic(); }),
                $method
            );
            throw new MemberAccessException("Call to undefined static method $class::$method()" . ($hint ? ", did you mean $hint()?" : '.'));
        }

        /**
         * Returns array of magic properties defined by annotation @param string $class
         * @return array
         * @throws ReflectionException
         * @noinspection PhpUnused*@property.
         * @noinspection PhpUnused
         */
        public static function getMagicProperties(string $class): array
        {
            static $cache;
            $props = &$cache[$class];
            if ($props !== null) {
                return $props;
            }

            $rc = new ReflectionClass($class);
            preg_match_all(
                '~^  [ \t*]*  @property(|-read|-write)  [ \t]+  [^\s$]+  [ \t]+  \$  (\w+)  ()~mx',
                (string) $rc->getDocComment(), $matches, PREG_SET_ORDER
            );

            $props = [];
            foreach ($matches as [, $type, $name]) {
                $uname = ucfirst($name);
                $write = $type !== '-read'
                    && $rc->hasMethod($nm = 'set' . $uname)
                    && ($rm = $rc->getMethod($nm))->name === $nm && !$rm->isPrivate() && !$rm->isStatic();
                $read = $type !== '-write'
                    && ($rc->hasMethod($nm = 'get' . $uname) || $rc->hasMethod($nm = 'is' . $uname))
                    && ($rm = $rc->getMethod($nm))->name === $nm && !$rm->isPrivate() && !$rm->isStatic();

                if ($read || $write) {
                    /** @noinspection PhpUndefinedVariableInspection */
                    $props[$name] = $read << 0 | ($nm[0] === 'g') << 1 | $rm->returnsReference() << 2 | $write << 3;
                }
            }

            foreach ($rc->getTraits() as $trait) {
                $props += self::getMagicProperties($trait->name);
            }

            if ($parent = get_parent_class($class)) {
                $props += self::getMagicProperties($parent);
            }
            return $props;
        }
    }