<?php


    namespace ppm\Classes\Composer\Lockfile;

    /**
     * Class Dist
     * @package ppm\Classes\Composer\Lockfile
     */
    class Dist extends Source
    {
        /**
         * @var string
         */
        protected $shasum;

        /**
         * Parses the given data and constructs a new instance from it.
         *
         * @param array $data The composer lockfile partial data
         */
        public function __construct(array $data = [])
        {
            $this->shasum = (array_key_exists('shasum', $data) ? $data['shasum'] : '');

            parent::__construct($data);
        }

        /**
         * Get the value of shasum
         *
         * @return  string
         */
        public function getShasum()
        {
            return $this->shasum;
        }
    }