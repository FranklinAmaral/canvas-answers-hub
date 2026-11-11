# Third-party notices: Nette

AdminHub GraderAI adopts the **BSD-3-Clause** licensing alternative offered by:

| Package | Version | Official license |
| --- | --- | --- |
| nette/schema | 1.3.5 | [license.md v1.3.5](https://raw.githubusercontent.com/nette/schema/v1.3.5/license.md) |
| nette/utils | 4.1.3 | [license.md v4.1.3](https://raw.githubusercontent.com/nette/utils/v4.1.3/license.md) |

The complete, unmodified upstream notice shared by these two versions is included
in [docs/licenses/NETTE-LICENSE.md](docs/licenses/NETTE-LICENSE.md). It retains the
copyright, conditions, disclaimer and upstream licensing alternatives. GraderAI
selects BSD-3-Clause for its use of these packages; retaining the original text
does not select GPL for this project.

Retain this file and the copied license in source distributions and in the
documentation/materials accompanying binary or packaged distributions. Composer
installations must also retain each package's original `license.md` and copyright
headers. Do not use the Nette name or contributor names to imply endorsement.

Run `php tools/check-nette-licenses.php` after installing Composer dependencies
and against the final distribution directory. When upgrading either package,
review its official license and update the documented adoption, notices and
verification before distributing the new version.

This selection applies only to the two packages above. Other dependencies retain
their own notices and terms; the project's proprietary license is unchanged.
Institutional Security/Compliance validation remains pending. Evidence is recorded
in [PACKAGE-REVIEW.md](docs/security/PACKAGE-REVIEW.md).
