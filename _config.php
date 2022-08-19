<?php

/**
 * @package phptek/silverstripe-exodus
 * @author Sam Minee <sam@silverstripe.com>
 * @author Russell Michell <russ@theruss.com>
 */

// Curl can get stuck on big files for while, so we increase execution time
// to 15m to cater for larger crawls and loading times
ini_set('max_execution_time', 900);

// GD complains about the state of some 3rd party JPEG's. Supress them.
ini_set('gd.jpeg_ignore_warning', 1);
