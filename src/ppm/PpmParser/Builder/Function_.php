<?php declare(strict_types=1);

    namespace PpmParser\Builder;

    use PpmParser;
    use PpmParser\BuilderHelpers;
    use PpmParser\Node;
    use PpmParser\Node\Stmt;

    /**
     * Class Function_
     * @package PpmParser\Builder
     */
    class Function_ extends FunctionLike
    {
        protected $name;
        protected $stmts = [];

        /**
         * Creates a function builder.
         *
         * @param string $name Name of the function
         */
        public function __construct(string $name)
        {
            $this->name = $name;
        }

        /**
         * Adds a statement.
         *
         * @param Node|PpmParser\Builder $stmt The statement to add
         *
         * @return $this The builder instance (for fluid interface)
         */
        public function addStmt($stmt)
        {
            $this->stmts[] = BuilderHelpers::normalizeStmt($stmt);

            return $this;
        }

        /**
         * Returns the built function node.
         *
         * @return Stmt\Function_ The built function node
         */
        public function getNode() : Node
        {
            return new Stmt\Function_($this->name, [
                'byRef'      => $this->returnByRef,
                'params'     => $this->params,
                'returnType' => $this->returnType,
                'stmts'      => $this->stmts,
            ], $this->attributes);
        }
    }
