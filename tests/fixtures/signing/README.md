# Test-only signing keys

`release-key.pem` / `release-key.pub` stand in for the lockrot self-update release key in tests;
`other-key.pem` / `other-key.pub` are a key of the same shape that is not it. Both pairs were
generated for the test suite, are checked in on purpose, and have never signed anything but test
strings: generating keys inside a test is slow on some runners and needs an `openssl.cnf` the php
Docker images do not always carry. They are RSA 4096 like the real key, so a test signature has the
real signature's length. The real public key is `lockrot-selfupdate-key.pub` in the repository
root; its private half is a repository secret and is not in any checkout.

`tests/Support/SigningKeys.php` reads them.
