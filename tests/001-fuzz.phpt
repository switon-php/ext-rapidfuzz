--TEST--
fuzz.php covers every public method, invalid UTF-8, and the protected constructor
--FILE--
<?php
// The name of this file is 001-fuzz.phpt so the temp file run-tests writes is
// not fuzz.php. Requiring the script keeps one source of truth for the checks.
require __DIR__ . '/fuzz.php';
?>
--EXPECT--
ok
