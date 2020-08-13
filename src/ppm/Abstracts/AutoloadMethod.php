<?php


    namespace ppm\Abstracts;

    /**
     * Class AutoloadMethod
     * @package ppm\Abstracts
     */
    abstract class AutoloadMethod
    {
        /**
         * Auto loads all components static by order from the Components array in
         * the package configuration file (package.json)
         *
         * This method can be slow for large packages and requires the component
         * order to be correct otherwise the loading may fail
         */
        const Static = "static";

        /**
         * Indexes the components during runtime with a cache-helper system and registers
         * and auto loader for the imported package
         *
         * Fast during runtime but can often cause issues when importing packages dynamically
         * due to an out-dated cache (if a lot of programs uses PPM)
         */
        const Indexed = "indexed";

        /**
         * Generates a static autoloader during the installation process of the package
         * which will be executed upon importing the package
         *
         * Can be slow for large packages, doesn't require you to manually list all
         * components in the correct order. This will affect all PHP files and not just
         * the files defined in components.
         */
        const GeneratedStatic = "generated_static";

        /**
         * Generates a standard spl_autoload_register script and registers the function.
         * This file will be executed upon importing the package.
         *
         * Fast during runtime and can be used to import packages dynamically.
         */
        const StandardPhpLibrary = "generated_spl";
    }