<?php
// @codingStandardsIgnoreFile
// @codeCoverageIgnoreStart
// this is an autogenerated file by PPM - do not edit
function ___AUTOLOAD___($class) {
    static $classes = null;
    if ($classes === null) {
        $classes = array(
            ___CLASSLIST___
        );
    }
    if (isset($classes[$class])) {
        require ___BASEDIR___$classes[$class];
    }
}
spl_autoload_register('___AUTOLOAD___', ___EXCEPTION___);
// @codeCoverageIgnoreEnd
