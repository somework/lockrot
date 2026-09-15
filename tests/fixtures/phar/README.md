# `minimal.phar`

A 254-byte PHP archive used by `tests/Unit/SelfUpdate/PharValidatorTest.php`. It is checked in
rather than generated at test time because `phar.readonly` defaults to On, so a test run cannot
create one.

Regenerate it with exactly this, from the repository root:

```bash
php -d phar.readonly=0 -r '
$path = "tests/fixtures/phar/minimal.phar";
@unlink($path);
$p = new Phar($path);
$p->addFromString("index.php", "<?php echo \"minimal\n\";");
$p->setStub("<?php Phar::mapPhar(\"lockrot-minimal-fixture.phar\"); require \"phar://lockrot-minimal-fixture.phar/index.php\"; __HALT_COMPILER(); ?>");
unset($p);
'
```

The fixed alias is deliberate: `PharValidatorTest` opens two copies of this archive in one process
to show that an alias already mapped by one file does not make the next one look damaged, which is
the situation `lockrot.phar self-update` is in when it checks a freshly downloaded archive.

`.gitattributes` marks `*.phar` as binary so the bytes survive a checkout on any platform.
