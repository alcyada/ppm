<?php


    namespace ppm\Classes\AutoloaderBuilder;


    // PHP 5.3 compat
    use ppm\Exceptions\ParserException;

     // PHP 5.3 compat
    define('T_TRAIT_53', 10355);
    if (!defined('T_TRAIT')) {
        define('T_TRAIT', -1);
    }

    // PHP 8.0 forward compat
    if (!defined('T_NAME_FULLY_QUALIFIED')) {
        define('T_NAME_FULLY_QUALIFIED', -1);
        define('T_NAME_QUALIFIED', -1);
    }

    

    /**
     * Namespace aware parser to find and extract defined classes within php source files
     */
    class Parser implements ParserInterface
    {

        private $methodMap = array(
            T_TRAIT      => 'processClass',
            T_TRAIT_53   => 'processClass',
            T_CLASS      => 'processClass',
            T_INTERFACE  => 'processInterface',
            T_NAMESPACE  => 'processNamespace',
            T_USE        => 'processUse',
            '}'          => 'processBracketClose',
            '{'          => 'processBracketOpen',
            T_CURLY_OPEN => 'processBracketOpen',
            T_DOLLAR_OPEN_CURLY_BRACES  => 'processBracketOpen'
        );

        private $typeMap = array(
            T_INTERFACE => 'interface',
            T_CLASS => 'class',
            T_TRAIT => 'trait',
            T_TRAIT_53 => 'trait'
        );

        private $caseInsensitive;

        private $tokenArray = array();

        private $inNamespace = '';
        private $inUnit = '';

        private $nsBracket = 0;
        private $classBracket = 0;

        private $bracketLevel = 0;
        private $aliases = array();

        private $found = array();
        private $dependencies = array();
        private $redeclarations = array();

        public function __construct($caseInsensitive = true)
        {
            $this->caseInsensitive = $caseInsensitive;
        }

        /**
         * Parse a given file for defintions of classes, traits and interfaces
         *
         * @param SourceFile $source file to process
         *
         * @return ParseResult
         */
        public function parse(SourceFile $source)
        {
            $this->found = array();
            $this->redeclarations = array();
            $this->inNamespace = '';
            $this->aliases = array();
            $this->bracketLevel = 0;
            $this->inUnit = '';
            $this->nsBracket = 0;
            $this->classBracket = 0;
            $this->tokenArray = $source->getTokens();
            $tokenCount = count($this->tokenArray);
            $tokList = array_keys($this->methodMap);
            for($t=0; $t<$tokenCount; $t++)
            {
                $current = (array)$this->tokenArray[$t];

                if ($current[0]===T_STRING && $current[1]==='trait' && T_TRAIT===-1)
                {
                    // PHP < 5.4 compat fix
                    $current[0] = T_TRAIT_53;
                    $this->tokenArray[$t] = $current;
                }

                if (!in_array($current[0], $tokList))
                {
                    continue;
                }

                $t = call_user_func(array($this, $this->methodMap[$current[0]]), $t);
            }
            return new ParseResult($this->found, $this->dependencies, $this->redeclarations);
        }

        /**
         * @param $pos
         * @return int
         */
        private function processBracketOpen($pos)
        {
            $this->bracketLevel++;
            return $pos + 1;
        }

        /**
         * @param $pos
         * @return int
         */
        private function processBracketClose($pos)
        {
            $this->bracketLevel--;
            if ($this->nsBracket !== 0 && $this->bracketLevel < $this->nsBracket)
            {
                $this->inNamespace = '';
                $this->nsBracket = 0;
                $this->aliases = array();
            }

            if ($this->bracketLevel <= $this->classBracket)
            {
                $this->classBracket = 0;
                $this->inUnit = '';
            }

            return $pos + 1;
        }

        /**
         * @param $pos
         * @return int
         * @throws ParserException
         */
        private function processClass($pos)
        {
            if (!$this->classTokenNeedsProcessing($pos))
            {
                return $pos;
            }
            $list = array('{');
            $stack = $this->getTokensTill($pos, $list);
            $stackSize = count($stack);
            $classname = $this->inNamespace !== '' ? $this->inNamespace . '\\' : '';
            $extends = '';
            $extendsFound = false;
            $implementsFound = false;
            $implementsList = array();
            $implements = '';
            $mode = 'classname';
            foreach(array_slice($stack, 1, -1) as $tok)
            {
                switch ($tok[0])
                {
                    case T_COMMENT:
                    case T_DOC_COMMENT:
                    case T_WHITESPACE:
                        break;


                    case T_NAME_FULLY_QUALIFIED:
                    case T_NAME_QUALIFIED:
                    case T_STRING:
                        $$mode .= $tok[1];
                        break;

                    case T_NS_SEPARATOR:
                        $$mode .= '\\';
                        break;

                    case T_EXTENDS:
                        $extendsFound = true;
                        $mode = 'extends';
                        break;

                    case T_IMPLEMENTS:
                        $implementsFound = true;
                        $mode = 'implements';
                        break;


                    case ',':
                        if ($mode === 'implements') {
                            $implementsList[] = $this->resolveDependencyName($implements);
                            $implements = '';
                        }
                        break;

                    default:
                        throw new ParserException(sprintf(
                            'Parse error while trying to process class definition (unexpected token "%s" in name).',
                            \token_name($tok[0])
                        ), ParserException::ParseError
                        );

                }
            }

            if ($implements != '')
            {
                $implementsList[] = $this->resolveDependencyName($implements);
            }
            if ($implementsFound && count($implementsList)==0)
            {
                throw new ParserException(sprintf(
                    'Parse error while trying to process class definition (extends or implements).'
                ), ParserException::ParseError
                );
            }

            $classname                      = $this->registerUnit($classname, $stack[0][0]);
            $this->dependencies[$classname] = $implementsList;

            if ($extendsFound)
            {
                $this->dependencies[$classname][] = $this->resolveDependencyName($extends);
            }
            $this->inUnit = $classname;
            $this->classBracket = $this->bracketLevel + 1;
            return $pos + $stackSize - 1;
        }

        /**
         * @param $pos
         * @return int
         * @throws ParserException
         */
        private function processInterface($pos)
        {
            $list = array('{');
            $stack = $this->getTokensTill($pos, $list);
            $stackSize = count($stack);
            $next = $stack[1];
            if (is_array($next) && $next[0] === '(')
            {
                // sort of inline use - ignore
                return $pos + $stackSize;
            }

            $name = $this->inNamespace != '' ? $this->inNamespace . '\\' : '';
            $extends = '';
            $extendsList = array();
            $mode = 'name';
            foreach(array_slice($stack, 1, -1) as $tok)
            {
                switch ($tok[0])
                {
                    case T_NS_SEPARATOR:
                    case T_NAME_QUALIFIED:
                    case T_NAME_FULLY_QUALIFIED:
                    case T_STRING:
                        $$mode .= $tok[1];
                        break;

                    case T_EXTENDS:
                        $mode = 'extends';
                        break;

                    case ',':
                        if ($mode == 'extends')
                        {
                            $extendsList[] = $this->resolveDependencyName($extends);
                            $extends = '';
                        }

                }
            }
            $name = $this->registerUnit($name, T_INTERFACE);

            if ($extends != '')
            {
                $extendsList[] = $this->resolveDependencyName($extends);
            }

            $this->dependencies[$name] = $extendsList;
            $this->inUnit = $name;
            return $pos + $stackSize - 1;
        }

        /**
         * @param $name
         * @return false|int|string
         * @throws ParserException
         */
        private function resolveDependencyName($name)
        {
            if ($name == '')
            {
                throw new ParserException(sprintf(
                    'Parse error while trying to process class definition (extends or implements).'
                ), ParserException::ParseError
                );
            }

            if ($name[0] == '\\')
            {
                $name = substr($name, 1);
            }
            else
            {
                $parts = explode('\\', $name, 2);
                $search = $this->caseInsensitive ? strtolower($parts[0]) : $parts[0];
                $key = array_search($search, $this->aliases);
                if (!$key)
                {
                    $name = ($this->inNamespace != '' ? $this->inNamespace . '\\' : ''). $name;
                }
                else
                {
                    $name = $key;
                    if (isset($parts[1]))
                    {
                        $name .= '\\' . $parts[1];
                    }
                }
            }

            if ($this->caseInsensitive)
            {
                $name = strtolower($name);
            }
            return $name;
        }

        /**
         * @param $name
         * @param $type
         * @return mixed|string
         * @throws ParserException
         */
        private function registerUnit($name, $type)
        {
            if ($name == '' || substr($name, -1) == '\\')
            {
                throw new ParserException(sprintf(
                    'Parse error while trying to process %s definition.',
                    $this->typeMap[$type]
                ), ParserException::ParseError
                );
            }
            if ($this->caseInsensitive)
            {
                $name = strtolower($name);
            }
            if (in_array($name, $this->found))
            {
                $this->redeclarations[] = $name;
            }
            else
            {
                $this->found[] = $name;
            }
            return $name;
        }

        /**
         * @param $pos
         * @return int
         */
        private function processNamespace($pos)
        {
            $list = array(';', '{');
            $stack = $this->getTokensTill($pos, $list);
            $stackSize = count($stack);
            $newpos = $pos + $stackSize;
            if ($stackSize < 3) { // empty namespace defintion == root namespace
                $this->inNamespace = '';
                $this->aliases = array();
                return $newpos - 1;
            }
            $next = $stack[1];
            if (is_array($next) && ($next[0] === T_NS_SEPARATOR || $next[0] === '('))
            {
                // sort of inline use - ignore
                return $newpos;
            }

            $this->inNamespace = '';
            foreach(array_slice($stack, 1, -1) as $tok)
            {
                $this->inNamespace .= $tok[1];
            }
            $this->aliases = array();

            return $pos + $stackSize - 1;
        }

        /**
         * @param $pos
         * @return int
         */
        private function processUse($pos)
        {
            $list = array(';','(');
            $stack = $this->getTokensTill($pos, $list);
            $stackSize = count($stack);
            $ignore = array(
                '(', // closue use
                T_CONST, // use const foo\bar;
                T_FUNCTION // use function foo\bar;
            );
            if (in_array($stack[1][0], $ignore))
            {
                return $pos + $stackSize - 1;
            }

            if ($this->classBracket > 0)
            {
                $this->parseUseOfTrait($stackSize, $stack);

            }
            else
            {
                $this->parseUseAsImport($stack);

            }
            return $pos + $stackSize - 1;
        }

        /**
         * @param $start
         * @param $list
         * @return array
         */
        private function getTokensTill($start, $list)
        {
            $list = (array)$list;
            $stack = array();
            $skip = array(
                T_WHITESPACE,
                T_COMMENT,
                T_DOC_COMMENT
            );
            $limit = count($this->tokenArray);
            for ($t=$start; $t<$limit; $t++)
            {
                $current = (array)$this->tokenArray[$t];
                if (in_array($current[0], $skip))
                {
                    continue;
                }
                $stack[] = $current;
                if (in_array($current[0], $list))
                {
                    break;
                }
            }
            return $stack;
        }

        /**
         * @param $stackSize
         * @param $stack
         */
        private function parseUseOfTrait($stackSize, $stack)
        {
            $use = '';
            for ($t = 0; $t < $stackSize; $t++)
            {
                $current = (array)$stack[$t];
                switch ($current[0])
                {
                    case '{':
                        // find closing bracket to skip contents
                        for ($x = $t + 1; $x < $stackSize; $x++)
                        {
                            $tok = $stack[$x];
                            if ($tok[0] == '}')
                            {
                                $t = $x;
                                break;
                            }
                        }
                        break;

                    case ';':
                    case ',':
                        $this->dependencies[$this->inUnit][] = $this->resolveDependencyName($use);
                        $use = '';
                        break;

                    case T_NS_SEPARATOR:
                    case T_NAME_QUALIFIED:
                    case T_NAME_FULLY_QUALIFIED:
                    case T_STRING:
                        $use .= $current[1];
                        break;
                }
            }
        }

        /**
         * @param $stack
         */
        private function parseUseAsImport($stack)
        {
            $use = '';
            $alias = '';
            $mode = 'use';
            $group = '';
            $ignore = false;
            foreach ($stack as $tok)
            {
                $current = $tok;
                switch ($current[0])
                {
                    case T_CONST:
                    case T_FUNCTION:
                        $ignore = true;
                        break;

                    case '{':
                        $group = $use;
                        break;

                    case ';':
                    case ',':
                        if (!$ignore)
                        {
                            if ($alias == '')
                            {
                                $nss = strrpos($use, '\\');
                                if ($nss !== FALSE)
                                {
                                    $alias = substr($use, $nss + 1);
                                }
                                else
                                {
                                    $alias = $use;
                                }
                            }
                            if ($this->caseInsensitive)
                            {
                                $alias = strtolower($alias);
                            }
                            $this->aliases[$use] = $alias;
                        }
                        $alias = '';
                        $use = $group;
                        $mode = 'use';
                        $ignore = false;
                        break;

                    case T_NS_SEPARATOR:
                    case T_NAME_QUALIFIED:
                    case T_NAME_FULLY_QUALIFIED:
                    case T_STRING:
                        $$mode .= $current[1];
                        break;

                    case T_AS:
                        $mode = 'alias';
                        break;

                }
            }
        }

        /**
         * @param $position
         * @return bool
         */
        private function classTokenNeedsProcessing($position)
        {

            // PHP 5.5 has classname::class, reusing T_CLASS
            if ($this->tokenArray[$position-1][0] == T_DOUBLE_COLON)
            {
                return false;
            }

            // PHP 7 has anonymous classes: $x = new class { ... }
            if ($position > 2 && $this->tokenArray[$position-2][0] === T_NEW)
            {
                return false;
            }

            if ($this->tokenArray[$position + 1] === '(' || $this->tokenArray[$position + 2] === '(')
            {
                return false;
            }

            return true;
        }

    }
